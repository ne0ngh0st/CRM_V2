<?php

namespace App\Http\Controllers;

use App\Jobs\AtualizarDadosTotvsJob;
use App\Jobs\ImportarLeadsProspeccaoJob;
use App\Models\Lead;
use App\Models\LeadImportacao;
use App\Models\TotvsImportacao;
use App\Services\Receita\SituacaoCadastral;
use App\Services\Totvs\AtualizadorTotvs;
use App\Services\Totvs\FrescorDoDado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use League\Flysystem\FileAttributes;
use Throwable;

/**
 * Tela de gestão das atualizações de dados do TOTVS (`/atualizacoes`).
 *
 * Existe para que o Tony não precise de SSH para responder três perguntas que só o
 * terminal respondia: o dado está velho? o que eu subi chegou no S3? a última rodada
 * funcionou? Estritamente admin-only — mostra estado de infraestrutura e dispara carga
 * pesada no banco.
 *
 * ⚠️ A TELA NÃO LÊ O DISCO. Os relatórios baixados vivem em `storage/app/totvs` da app-2;
 * esta página é servida pelo ALB e cai em qualquer um dos dois nós. Tudo que ela mostra
 * vem do banco (histórico das rodadas, frescor do dado) ou do S3 (inventário do que foi
 * enviado) — as duas fontes que os dois nós enxergam igual. Ler o diretório local daria
 * "nenhum relatório" de forma intermitente, conforme o nó sorteado.
 */
class AtualizacaoDadosController extends Controller
{
    /** Quantas rodadas o histórico mostra. */
    private const RODADAS_NO_HISTORICO = 15;

    /**
     * O inventário do S3 muda no ritmo em que o Tony sobe arquivo (uma vez por dia, no
     * máximo), mas a tela recarrega sozinha a cada 4 s enquanto uma rodada está em
     * andamento. Sem cache, cada uma dessas recargas viraria um ListObjects.
     */
    private const CACHE_S3_SEGUNDOS = 60;

    public function __construct(private readonly FrescorDoDado $frescorDoDado) {}

    public function index(Request $request): Response
    {
        $this->autorizarAdmin($request);

        return Inertia::render('Atualizacoes/Index', [
            'frescor' => $this->frescor(),
            'receita' => $this->baseDaReceita(),
            'leadsProspeccao' => $this->leadsProspeccao(),
            'relatorios' => $this->relatoriosNoS3(),
            'rodadas' => $this->rodadas(),
            'emAndamento' => $this->rodadaEmAndamento() !== null,
            // ⚠️ `aviso` e NÃO `flash`: o `HandleInertiaRequests` já compartilha uma prop
            // chamada `flash`, e no Inertia a prop de PÁGINA sobrescreve a compartilhada.
            // Reusar o nome apagaria o `recursoCriadoId` nesta página — inofensivo hoje
            // (ela não usa), mas é exatamente a armadilha que custou o alternador de visão
            // invisível em 2026-09-03.
            'aviso' => [
                'sucesso' => $request->session()->get('sucesso'),
                'erro' => $request->session()->get('erro'),
            ],
        ]);
    }

    /**
     * A carga mensal da base aberta da Receita (situação dos CNPJs de leads e clientes).
     *
     * ⚠️ Fica FORA de `FrescorDoDado::porDominio()` de propósito: lá ela entraria no
     * `pior()` e a pill do Painel acusaria "desatualizado" a cada 3 dias úteis numa base
     * que só muda uma vez por mês. A regra de idade é a de `SituacaoCadastral`.
     *
     * @return array<string, mixed>
     */
    private function baseDaReceita(): array
    {
        $idade = app(SituacaoCadastral::class)->idadeDaBase();

        return [
            'referencia' => $idade['referencia'],
            'carregadaEm' => $idade['carregadaEm']?->toIso8601String(),
            'dias' => $idade['dias'],
            'tom' => $idade['tom'],
            'porSituacao' => DB::table('cnpj_situacoes')
                ->selectRaw('situacao, COUNT(*) as total')
                ->groupBy('situacao')->orderByDesc('total')
                ->pluck('total', 'situacao'),
        ];
    }

    /**
     * O card "Leads da prospecção": a última rodada do `totvs:import-leads` (os CSVs da
     * pasta Leads/) e o estado de agora. O que interessa é sobretudo o que ficou de fora
     * e por quê. Os números da rodada são os que o próprio comando gravou — esta tela não
     * recalcula nada.
     *
     * Existe mesmo sem rodada nenhuma: é onde mora o botão que faz a primeira.
     *
     * Se a última foi simulação, `ultimaReal` diz quando os leads entraram de verdade:
     * sem isso, um dry-run de hoje esconderia que a importação real é de semanas atrás.
     *
     * @return array<string, mixed>
     */
    private function leadsProspeccao(): array
    {
        $ultima = LeadImportacao::query()->with('user:id,name,display_name')->latest('id')->first();

        $ultimaReal = $ultima?->simulacao
            ? LeadImportacao::query()->where('simulacao', false)->where('status', 'sucesso')->latest('id')->value('concluida_em')
            : $ultima?->concluida_em;

        return [
            'emAndamento' => LeadImportacao::query()->emAndamento()->exists(),
            'ultima' => $ultima === null ? null : [
                'id' => $ultima->id,
                'simulacao' => $ultima->simulacao,
                // A órfã (worker morreu no meio) aparece como travada, não "executando" para sempre.
                'status' => $ultima->travou() ? 'travada' : $ultima->status,
                'erro' => $ultima->erro,
                'em' => ($ultima->concluida_em ?? $ultima->iniciada_em)?->toIso8601String(),
                'por' => $ultima->user?->display_name ?: $ultima->user?->name,
                'arquivos' => $ultima->arquivos ?? [],
                'resultado' => $ultima->resultado,
                'recusadas' => $ultima->recusadas ?? [],
            ],
            'ultimaReal' => $ultimaReal ? Carbon::parse($ultimaReal)->toIso8601String() : null,
            // Estado de AGORA, não da rodada: alguém pode ter decidido depois dela.
            'sugestoesPendentes' => Lead::query()->visivel()->where('conta_vinculo', Lead::CONTA_SUGERIDA)->count(),
            'prospeccaoNoCrm' => Lead::query()->visivel()->where('origem', Lead::ORIGEM_PROSPECCAO)->count(),
            // Mesmo recorte do link (/leads?origem=prospeccao&sem_vendedor=1), para o número bater.
            'semVendedor' => Lead::query()->visivel()->where('origem', Lead::ORIGEM_PROSPECCAO)->whereNull('cod_vendedor')->count(),
        ];
    }

    /**
     * A lista por trás de um número do card (o clique). Lida aqui, sob demanda, e não no
     * carregamento: pode ter milhares de linhas, e a página recarrega sozinha a cada 4 s
     * durante uma rodada.
     *
     * ⚠️ `chave` é whitelist (`LeadImportacao::DETALHES` + `recusadas`), não organização.
     */
    public function detalheLeads(Request $request, LeadImportacao $rodada, string $chave): JsonResponse
    {
        $this->autorizarAdmin($request);

        if ($chave === 'recusadas') {
            return response()->json([
                'titulo' => 'Linhas fora do padrão',
                'itens' => collect($rodada->recusadas ?? [])->map(fn (string $l) => ['cnpj' => null, 'nome' => $l, 'info' => null])->all(),
                'total' => (int) ($rodada->resultado['recusadas'] ?? 0),
            ]);
        }

        abort_unless(array_key_exists($chave, LeadImportacao::DETALHES), 404);

        $itens = $rodada->detalhes[$chave] ?? [];

        return response()->json([
            'titulo' => LeadImportacao::DETALHES[$chave],
            'itens' => $itens,
            // O total real, para a tela dizer "mostrando N de M" quando a lista foi cortada.
            'total' => max(count($itens), $this->totalDoResultado($rodada->resultado ?? [], $chave)),
        ]);
    }

    /** @param  array<string, mixed>  $r */
    private function totalDoResultado(array $r, string $chave): int
    {
        return (int) match ($chave) {
            'naoAtivos' => array_sum($r['receitaPorSituacao'] ?? []),
            'viraramCliente' => $r['leadsQueViraramCliente'] ?? 0,
            'redesCriadas' => $r['contas']['criadas'] ?? 0,
            'ligados' => $r['contas']['confirmados'] ?? 0,
            default => $r[$chave] ?? 0,
        };
    }

    /**
     * Botões "Simular" e "Importar leads" do card. Mesmo desenho do `disparar()`: a linha
     * nasce `executando` AQUI, no request — a tela entra em acompanhamento no redirect e
     * o guarda de "já existe uma em andamento" vale antes de o worker pegar o job.
     */
    public function importarLeads(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        if (LeadImportacao::query()->emAndamento()->exists()) {
            return back()->with('erro', 'Já existe uma importação de leads em andamento.');
        }

        $simulacao = $request->boolean('simulacao');

        $rodada = LeadImportacao::query()->create([
            'simulacao' => $simulacao,
            'user_id' => $request->user()->id,
            'status' => 'executando',
            'iniciada_em' => now(),
        ]);

        ImportarLeadsProspeccaoJob::dispatch($rodada->id, $simulacao);

        return back()->with('sucesso', $simulacao
            ? 'Simulação dos leads enviada para a fila — nada será gravado.'
            : 'Importação dos leads enviada para a fila.');
    }

    public function disparar(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        if ($this->rodadaEmAndamento() !== null) {
            return back()->with('erro', 'Já existe uma atualização em andamento.');
        }

        // ⚠️ A linha nasce AQUI, dentro do request, e não no worker. É o que faz
        // `emAndamento` já voltar `true` no redirect: a tela entra em modo de
        // acompanhamento na hora, em vez de mostrar "nenhuma rodada" logo depois do
        // clique. De quebra, o guarda de "já existe uma em andamento" passa a valer
        // mesmo antes de o worker pegar o job.
        $rodada = TotvsImportacao::create([
            'status' => 'executando',
            'origem' => 'manual',
            'user_id' => $request->user()->id,
            'iniciada_em' => now(),
        ]);

        AtualizarDadosTotvsJob::dispatch(
            userId: $request->user()->id,
            forcar: $request->boolean('forcar'),
            rodadaId: $rodada->id,
        );

        return back()->with('sucesso', $request->boolean('forcar')
            ? 'Reimportação forçada enviada para a fila.'
            : 'Atualização enviada para a fila.');
    }

    /**
     * Só admin. Checado no controller e não em middleware de rota, igual ao
     * `EtiquetaMateriaPrimaController` e ao CRUD do Catálogo de Facas.
     */
    private function autorizarAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }

    private function rodadaEmAndamento(): ?TotvsImportacao
    {
        $ultima = TotvsImportacao::query()->latest('iniciada_em')->first();

        // `emAndamento()` já descarta a rodada travada (worker morto no meio) — sem isso o
        // botão nunca mais liberaria. Ver TotvsImportacao::MINUTOS_ATE_CONSIDERAR_TRAVADA.
        return $ultima?->emAndamento() ? $ultima : null;
    }

    /**
     * Quão velho está cada domínio. É a pergunta que motivou a tela: em 2026-09-04 a
     * produção passou um mês com dado parado e os 12 alarmes do CloudWatch ficaram verdes,
     * porque CPU e ALB não sabem a idade da última nota fiscal.
     *
     * ⚠️ O `MAX()` é barato e fica AO VIVO: as duas colunas são a primeira chave de um
     * índice, então o MySQL lê a última entrada e para — 0,3 ms medido em produção com 6
     * milhões de linhas.
     *
     * ⚠️ O `COUNT(*)` NÃO é barato e por isso é cacheado: 943 ms em `faturamentos`, porque
     * o InnoDB não guarda contador e varre o índice inteiro (`type=index`, `Using index`).
     * Sem o cache a página custava 962 ms — mais que o dobro do orçamento de 400 ms da
     * Regra de ouro nº 9 —, e como a tela se recarrega a cada 4 s durante uma importação,
     * seriam 943 ms de RDS a cada 4 s. A contagem é invalidada ao fim de toda importação
     * bem-sucedida (ver `AtualizadorTotvs::CHAVE_CACHE_CONTAGENS`).
     *
     * @return list<array<string, mixed>>
     */
    private function frescor(): array
    {
        // ⚠️ A regra de "quão velho está" mora no `FrescorDoDado`, não aqui. Quando ela
        // era privada deste controller, a pill do Painel tinha a sua própria — e ficou um
        // mês mentindo enquanto esta tela dizia a verdade (Regra de ouro nº 8).
        $contagens = app(AtualizadorTotvs::class)->contagens();

        return array_map(
            fn (array $item) => $item + ['linhas' => $contagens[$item['tabela']] ?? null],
            $this->frescorDoDado->porDominio()
        );
    }

    /**
     * O que existe hoje em `s3://.../totvs/` — é o inventário do que o Tony enviou com
     * `infra/enviar-relatorios-totvs.sh`, e a resposta para "será que subiu?".
     *
     * ⚠️ Uma chamada só. `Storage::size()`/`lastModified()` por arquivo seriam 10 HEADs
     * extras; o `listContents` do Flysystem já devolve tamanho e data no próprio
     * ListObjects.
     *
     * ⚠️ Falha de S3 não pode derrubar a tela: sem o inventário a página ainda responde as
     * outras duas perguntas (frescor e histórico), que é o que mais importa quando algo
     * está errado.
     *
     * @return array{itens: list<array<string, mixed>>, erro: ?string}
     */
    private function relatoriosNoS3(): array
    {
        return Cache::remember('totvs:inventario-s3', self::CACHE_S3_SEGUNDOS, function () {
            try {
                $itens = [];

                foreach (Storage::disk('s3')->getDriver()->listContents('totvs', true) as $objeto) {
                    if (! $objeto instanceof FileAttributes) {
                        continue;
                    }

                    $caminho = $objeto->path();

                    $itens[] = [
                        'caminho' => str_starts_with($caminho, 'totvs/') ? substr($caminho, 6) : $caminho,
                        'bytes' => $objeto->fileSize(),
                        'enviado_em' => $objeto->lastModified()
                            ? \Illuminate\Support\Carbon::createFromTimestamp($objeto->lastModified())->toIso8601String()
                            : null,
                    ];
                }

                usort($itens, fn ($a, $b) => strcmp($a['caminho'], $b['caminho']));

                return ['itens' => $itens, 'erro' => null];
            } catch (Throwable $e) {
                return ['itens' => [], 'erro' => $e->getMessage()];
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function rodadas(): array
    {
        return TotvsImportacao::query()
            ->with('user:id,display_name,name')
            ->latest('iniciada_em')
            ->limit(self::RODADAS_NO_HISTORICO)
            ->get()
            ->map(fn (TotvsImportacao $r) => [
                'id' => $r->id,
                // A rodada travada é mostrada como tal, não como "executando" eterno.
                'status' => $r->travada() ? 'travada' : $r->status,
                'origem' => $r->origem,
                'quem' => $r->user?->display_name ?: $r->user?->name,
                'iniciada_em' => $r->iniciada_em?->toIso8601String(),
                'duracao' => $r->duracaoSegundos(),
                'passos' => $r->passos ?? [],
                'erro' => $r->erro,
            ])
            ->all();
    }
}
