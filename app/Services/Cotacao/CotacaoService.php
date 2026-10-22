<?php

namespace App\Services\Cotacao;

use App\Jobs\ProcessarCotacaoJob;
use App\Models\Cotacao;
use App\Models\CotacaoDecisao;
use App\Models\CotacaoItem;
use App\Models\CotacaoMemoria;
use App\Models\EstoqueRevenda;
use App\Models\User;
use App\Support\Uploads\Disco;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tudo que a Cotação decide mora aqui (Regra de ouro nº 8): quem usa, quem vê custo,
 * os rótulos de cada status, a chamada ao motor Python e a memória das conferências.
 *
 * O motor é o do antigo Cotacao.exe, sem alteração de lógica (motor/cotacao). Este
 * serviço só monta a entrada (catálogo + memória em JSON), roda e grava a saída.
 */
class CotacaoService
{
    /** Quem usa a tela (decisão do Tony, 22/10/2026). */
    public const PERFIS = ['vendedor', 'representante', 'supervisor', 'diretor', 'admin'];

    /** Quem vê o Ult.Preco do Relatório de Revenda — é custo de COMPRA. */
    public const PERFIS_CUSTO = ['supervisor', 'diretor', 'admin'];

    /**
     * Rótulo e tom (`StatusPill`) de cada status do motor. O front não tem cópia: recebe
     * `statusRotulo`/`statusTom` prontos.
     */
    public const STATUS = [
        'APRENDIDO' => ['rotulo' => 'Já validado antes', 'tom' => 'ok'],
        'AUTOMATICO' => ['rotulo' => 'Encontrado com segurança', 'tom' => 'ok'],
        'REVISAR' => ['rotulo' => 'Precisa de conferência', 'tom' => 'warn'],
        'TALVEZ' => ['rotulo' => 'Parecido, mas incerto', 'tom' => 'warn'],
        'SEM_MATCH' => ['rotulo' => 'Não encontrado', 'tom' => 'danger'],
        'SEM_MATCH_CONFIRMADO' => ['rotulo' => 'Não trabalhamos', 'tom' => 'neutral'],
    ];

    /** O que entra na fila de conferência (mesma regra do Cotacao.exe). */
    public const REVISAVEIS = ['REVISAR', 'TALVEZ', 'SEM_MATCH'];

    public function podeUsar(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::PERFIS);
    }

    public function podeVerCusto(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(self::PERFIS_CUSTO);
    }

    /** Admin vê todas; o resto, só as próprias. */
    public function podeVer(User $user, Cotacao $cotacao): bool
    {
        return $cotacao->user_id === $user->id || $user->hasRole('admin');
    }

    // ------------------------------------------------------------------ processamento

    /**
     * Guarda a lista e enfileira a comparação. Sempre assíncrono: o cruzamento leva de
     * 5 a 40 s (Regra de ouro nº 9 — acima de 2 s não pode ser síncrono).
     */
    public function iniciar(User $user, UploadedFile $arquivo, ?int $clienteId): Cotacao
    {
        $extensao = strtolower($arquivo->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $caminho = 'cotacoes/'.Str::uuid().'.'.$extensao;
        Disco::exports()->put($caminho, $arquivo->get());

        $cotacao = Cotacao::create([
            'user_id' => $user->id,
            'cliente_id' => $clienteId,
            'nome_arquivo' => mb_substr($arquivo->getClientOriginalName(), 0, 255),
            'caminho_arquivo' => $caminho,
            'status' => Cotacao::STATUS_PROCESSANDO,
        ]);

        ProcessarCotacaoJob::dispatch($cotacao->id);

        return $cotacao;
    }

    public function processar(Cotacao $cotacao): void
    {
        $pasta = storage_path('app/cotacao/tmp/'.$cotacao->id.'-'.Str::random(8));
        File::ensureDirectoryExists($pasta);

        try {
            $extensao = pathinfo($cotacao->caminho_arquivo, PATHINFO_EXTENSION);
            $pedido = $pasta.'/lista.'.$extensao;
            File::put($pedido, Disco::exports()->get($cotacao->caminho_arquivo));

            $estoque = EstoqueRevenda::query()
                ->get(['codigo', 'descricao', 'marca', 'grupo', 'fisico', 'reserva', 'disponivel', 'comprado', 'ult_preco']);

            if ($estoque->isEmpty()) {
                throw new CotacaoRecusada('O estoque de revenda ainda não foi carregado (Relatório de Revenda do TOTVS).');
            }

            File::put($pasta.'/catalogo.json', json_encode($estoque->map(fn (EstoqueRevenda $e) => [
                'codigo' => $e->codigo,
                'descricao' => $e->descricao,
                'marca' => $e->marca,
                'grupo' => $e->grupo,
                'preco' => $e->ult_preco,
                'fisico' => $e->fisico,
                'reserva' => $e->reserva,
                'disponivel' => $e->disponivel,
                'comprado' => $e->comprado,
            ])->all(), JSON_UNESCAPED_UNICODE));
            File::put($pasta.'/memoria.json', json_encode($this->memoriaParaMotor(), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));

            $saida = $this->rodarMotor([
                'casar',
                '--pedido', $pedido,
                '--catalogo', $pasta.'/catalogo.json',
                '--memoria', $pasta.'/memoria.json',
                '--saida', $pasta.'/resultado.json',
            ], $pasta.'/resultado.json');

            $this->gravarResultado($cotacao, $saida, EstoqueRevenda::max('updated_at'));
        } finally {
            File::deleteDirectory($pasta);
        }
    }

    /**
     * Decisões no formato que `motor.casar(memoria=...)` lê: texto → {decisao, codigo,
     * usuario, data}. O nome e a data entram no motivo ("par validado por X em Y").
     *
     * @return array<string, array<string, mixed>>
     */
    public function memoriaParaMotor(): array
    {
        $nomes = User::query()
            ->whereIn('id', CotacaoMemoria::query()->whereNotNull('user_id')->select('user_id'))
            ->get(['id', 'name', 'display_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->display_name ?: $u->name]);

        return CotacaoMemoria::query()->get()->mapWithKeys(fn (CotacaoMemoria $m) => [
            $m->texto_normalizado => [
                'decisao' => $m->decisao,
                'codigo' => $m->codigo ?? '',
                'usuario' => $m->user_id ? ($nomes[$m->user_id] ?? $m->quem) : $m->quem,
                'data' => $m->atualizado_em?->format('d/m/Y'),
            ],
        ])->all();
    }

    /**
     * Roda a CLI do motor e devolve o JSON de saída. Código 2 é erro de LEITURA da lista
     * (mensagem para o usuário); qualquer outro é defeito, e vai para o log.
     */
    private function rodarMotor(array $argumentos, string $arquivoSaida): mixed
    {
        $resultado = Process::timeout((int) config('cotacao.timeout'))
            ->run([config('cotacao.python'), '-I', config('cotacao.cli'), ...$argumentos]);

        if ($resultado->exitCode() === 2) {
            throw new CotacaoRecusada(trim($resultado->errorOutput()) ?: 'Não consegui ler a lista do cliente.');
        }

        if (! $resultado->successful() || ! is_file($arquivoSaida)) {
            Log::error('Motor da cotação falhou', [
                'codigo' => $resultado->exitCode(),
                'erro' => mb_substr($resultado->errorOutput(), -4000),
            ]);

            throw new RuntimeException('O motor da cotação falhou (código '.$resultado->exitCode().').');
        }

        return json_decode(File::get($arquivoSaida), true, flags: JSON_THROW_ON_ERROR);
    }

    private function gravarResultado(Cotacao $cotacao, array $saida, ?string $estoqueEm): void
    {
        $agora = now();
        $linhas = array_map(fn (array $i) => [
            'cotacao_id' => $cotacao->id,
            'cod_cliente' => Str::limit((string) ($i['cod_cliente'] ?? ''), 60, '') ?: null,
            'descricao_cliente' => (string) $i['descricao'],
            'texto_normalizado' => mb_substr((string) $i['texto_normalizado'], 0, 512),
            'subgrupo' => Str::limit((string) ($i['subgrupo'] ?? ''), 255, '') ?: null,
            'unidade' => Str::limit((string) ($i['unidade'] ?? ''), 30, '') ?: null,
            'marca' => Str::limit((string) ($i['marca'] ?? ''), 255, '') ?: null,
            'qtd_periodo' => (float) ($i['qtd_periodo'] ?? 0),
            'valor_periodo' => round((float) ($i['valor_periodo'] ?? 0), 2),
            'ordens' => (int) ($i['ordens'] ?? 0),
            'preco_unit' => (float) ($i['preco_unit'] ?? 0),
            'status_motor' => $i['status'],
            'status' => $i['status'],
            'score' => (float) $i['score'],
            'motivos' => json_encode($i['motivos'], JSON_UNESCAPED_UNICODE),
            'codigo_sugerido' => $i['codigo'],
            'alternativas' => json_encode($i['alternativas']),
            'pulado' => false,
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $saida['itens']);

        DB::transaction(function () use ($cotacao, $linhas, $saida, $estoqueEm, $agora) {
            foreach (array_chunk($linhas, 500) as $lote) {
                CotacaoItem::insert($lote);
            }

            $cotacao->update([
                'status' => Cotacao::STATUS_PRONTA,
                'erro' => null,
                'leitura' => $saida['leitura'],
                'itens_total' => count($linhas),
                'valor_total' => array_sum(array_column($linhas, 'valor_periodo')),
                'estoque_em' => $estoqueEm,
                'processada_em' => $agora,
            ]);
        });
    }

    // ------------------------------------------------------------------ conferência

    /** Itens que ainda esperam alguém, maior valor primeiro (dá para parar no meio). */
    public function pendentes(Cotacao $cotacao)
    {
        return $cotacao->itens()
            ->whereIn('status', self::REVISAVEIS)
            ->where('pulado', false)
            ->orderByDesc('valor_periodo')
            ->orderBy('id');
    }

    /**
     * Grava a conferência: histórico + memória na mesma transação, e aplica a decisão a
     * TODOS os itens da cotação com o mesmo texto normalizado (são o mesmo produto para o
     * motor — conferir um deixaria o outro na fila à toa).
     *
     * `$codigo` nulo = "não trabalhamos com esse".
     */
    public function decidir(CotacaoItem $item, User $user, ?string $codigo): void
    {
        $nossa = null;

        if ($codigo !== null) {
            $nossa = EstoqueRevenda::query()->where('codigo', $codigo)->value('descricao');

            if ($nossa === null) {
                throw new CotacaoRecusada('Esse código não está no estoque de revenda.');
            }
        }

        // OK quando confirmou o que o motor já tinha escolhido; TROCAR quando escolheu
        // outro (inclusive em TALVEZ/SEM_MATCH, onde o motor não escolheu nada).
        $decisao = $codigo === null ? 'NAO_TEMOS' : ($codigo === $item->codigo_sugerido ? 'OK' : 'TROCAR');

        DB::transaction(function () use ($item, $user, $codigo, $nossa, $decisao) {
            $registro = CotacaoDecisao::create([
                'texto_normalizado' => $item->texto_normalizado,
                'descricao_cliente' => $item->descricao_cliente,
                'decisao' => $decisao,
                'codigo' => $codigo,
                'descricao_nossa' => $nossa,
                'user_id' => $user->id,
                'cotacao_id' => $item->cotacao_id,
                'origem' => $item->cotacao?->nome_arquivo,
            ]);

            CotacaoMemoria::query()->updateOrCreate(
                ['texto_normalizado' => $item->texto_normalizado],
                [
                    'descricao_cliente' => $item->descricao_cliente,
                    'decisao' => $decisao,
                    'codigo' => $codigo,
                    'descricao_nossa' => $nossa,
                    'user_id' => $user->id,
                    'quem' => null,
                    'decisao_id' => $registro->id,
                    'atualizado_em' => now(),
                ]
            );

            CotacaoItem::query()
                ->where('cotacao_id', $item->cotacao_id)
                ->where('texto_normalizado', $item->texto_normalizado)
                ->update([
                    'status' => $codigo === null ? 'SEM_MATCH_CONFIRMADO' : 'APRENDIDO',
                    'decisao' => $decisao,
                    'codigo_escolhido' => $codigo,
                    'pulado' => false,
                    'decidido_por' => $user->id,
                    'decidido_em' => now(),
                ]);
        });
    }

    /** Sai da fila desta cotação sem gravar nada na memória (igual ao "pular" do .exe). */
    public function pular(CotacaoItem $item): void
    {
        $item->update(['pulado' => true]);
    }

    /**
     * Desfaz: ANULADO no histórico (o clique errado não some da auditoria), apaga a
     * decisão atual e devolve os itens desta cotação ao que o motor tinha respondido.
     */
    public function esquecer(CotacaoItem $item, User $user): void
    {
        DB::transaction(function () use ($item, $user) {
            CotacaoDecisao::create([
                'texto_normalizado' => $item->texto_normalizado,
                'descricao_cliente' => $item->descricao_cliente,
                'decisao' => 'ANULADO',
                'user_id' => $user->id,
                'cotacao_id' => $item->cotacao_id,
                'origem' => $item->cotacao?->nome_arquivo,
            ]);

            CotacaoMemoria::query()->whereKey($item->texto_normalizado)->delete();

            CotacaoItem::query()
                ->where('cotacao_id', $item->cotacao_id)
                ->where('texto_normalizado', $item->texto_normalizado)
                ->update([
                    'status' => DB::raw('status_motor'),
                    'decisao' => null,
                    'codigo_escolhido' => null,
                    'pulado' => false,
                    'decidido_por' => null,
                    'decidido_em' => null,
                ]);
        });
    }

    // ------------------------------------------------------------------ memória

    /**
     * Texto normalizado de cada descrição, calculado PELO MOTOR. É a única forma de
     * produzir a chave da memória — nunca reimplementar em PHP.
     *
     * @param  list<string>  $textos
     * @return list<string>
     */
    public function normalizar(array $textos): array
    {
        if ($textos === []) {
            return [];
        }

        $pasta = storage_path('app/cotacao/tmp/norm-'.Str::random(8));
        File::ensureDirectoryExists($pasta);

        try {
            File::put($pasta.'/entrada.json', json_encode(array_values($textos), JSON_UNESCAPED_UNICODE));

            return $this->rodarMotor([
                'normalizar', '--entrada', $pasta.'/entrada.json', '--saida', $pasta.'/saida.json',
            ], $pasta.'/saida.json');
        } finally {
            File::deleteDirectory($pasta);
        }
    }

    // ------------------------------------------------------------------ apresentação

    /**
     * Candidatos de um item já com descrição/estoque — o sugerido primeiro, depois as
     * alternativas, sem repetir código. Mesma regra do `candidatos_de()` do .exe.
     *
     * @param  Collection<string, EstoqueRevenda>  $estoque  indexado por código
     */
    public function candidatos(CotacaoItem $item, Collection $estoque, bool $comCusto): array
    {
        $lista = [];

        if ($item->codigo_sugerido !== null) {
            $lista[] = ['codigo' => $item->codigo_sugerido, 'score' => $item->score];
        }

        foreach ($item->alternativas ?? [] as $alt) {
            $lista[] = $alt;
        }

        $vistos = [];
        $saida = [];

        foreach ($lista as $c) {
            if (isset($vistos[$c['codigo']])) {
                continue;
            }
            $vistos[$c['codigo']] = true;
            $saida[] = $this->produto($estoque[$c['codigo']] ?? null, $c['codigo'], $comCusto) + [
                'score' => (int) round($c['score']),
            ];
        }

        return $saida;
    }

    /** Um produto do estoque para a tela. Custo só entra quando pode. */
    public function produto(?EstoqueRevenda $e, string $codigo, bool $comCusto): array
    {
        $dados = [
            'codigo' => $codigo,
            'descricao' => $e?->descricao,
            'marca' => $e?->marca,
            'disponivel' => $e?->disponivel,
            'comprado' => $e?->comprado,
            'noEstoque' => $e !== null,
        ];

        if ($comCusto) {
            $dados['custo'] = $e?->ult_preco;
        }

        return $dados;
    }

    /** @return array{rotulo: string, tom: string} */
    public static function rotulo(string $status): array
    {
        return self::STATUS[$status] ?? ['rotulo' => $status, 'tom' => 'neutral'];
    }
}
