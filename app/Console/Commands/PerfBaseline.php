<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Perf\ContadorDeQueries;
use App\Support\Perf\Medicao;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Baseline de performance das páginas core, por perfil.
 *
 * Mede o que é DETERMINÍSTICO (queries, bytes de payload, pico de memória) para que o
 * antes/depois de cada otimização seja comparável mesmo rodando em Docker sobre WSL2,
 * onde o wall-clock é ruído. Ver docs/performance.md e a Regra de ouro nº 9.
 *
 * SOMENTE LEITURA: percorre apenas rotas GET, nunca dispara POST/PATCH/DELETE.
 */
class PerfBaseline extends Command
{
    protected $signature = 'perf:baseline
        {--perfis=admin,supervisor,vendedor : Perfis a medir, separados por vírgula}
        {--rotas= : Subconjunto de chaves de config(perf.rotas_core), separadas por vírgula}
        {--cache=ambos : frio | quente | ambos}
        {--repeticoes=3 : Execuções por medição (descarta a 1ª, usa a mediana do resto)}
        {--sql : Mostra os SQLs da pior rota (para caçar N+1)}
        {--json= : Salva o snapshot com este nome em storage/app/perf/}
        {--comparar= : Compara com um snapshot salvo anteriormente}';

    protected $description = 'Mede queries, payload e memória das páginas core por perfil (somente leitura)';

    public function handle(): int
    {
        if (! $this->confirmarAlvo()) {
            return self::FAILURE;
        }

        $rotas = $this->rotasSelecionadas();
        if ($rotas === []) {
            $this->error('Nenhuma rota selecionada.');

            return self::FAILURE;
        }

        $modos = match ($this->option('cache')) {
            'frio' => ['frio'],
            'quente' => ['quente'],
            default => ['frio', 'quente'],
        };

        $linhas = [];

        foreach (explode(',', (string) $this->option('perfis')) as $perfil) {
            $perfil = trim($perfil);
            $usuario = $this->usuarioPara($perfil);

            if (! $usuario) {
                $this->warn("  Perfil '{$perfil}': nenhum usuário ativo encontrado — pulando.");

                continue;
            }

            $this->line("  <fg=cyan>{$perfil}</> → usuário #{$usuario->id} ({$usuario->email})");

            foreach ($rotas as $chave => $conf) {
                if (! $this->perfilPodeAcessar($perfil, $conf)) {
                    continue;
                }

                foreach ($modos as $modo) {
                    foreach ($this->medirRota($usuario, $conf, $modo) as $tipo => $medicao) {
                        if (! $this->pior || $medicao->queries > $this->pior->queries) {
                            $this->pior = $medicao;
                            $this->piorRotulo = "{$perfil} · {$chave} · {$tipo} ({$modo})";
                        }

                        $linhas[] = [
                            'perfil' => $perfil,
                            'rota' => $chave,
                            'tipo' => $tipo,
                            'modo' => $modo,
                            'queries' => $medicao->queries,
                            'ms_sql' => $medicao->msSql,
                            'payload_kb' => $medicao->payloadKb(),
                            'memoria_mb' => $medicao->picoMemoriaMb(),
                            'ms_wall' => $medicao->msWall,
                        ];
                    }
                }
            }
        }

        $this->newLine();
        $this->renderTabela($linhas);
        $this->renderForaDoOrcamento($linhas);
        $this->renderSqlsRepetidos();
        $this->renderAvisoDeRuido();

        if ($nome = $this->option('json')) {
            $this->salvarSnapshot($nome, $linhas);
        }

        if ($anterior = $this->option('comparar')) {
            $this->compararCom($anterior, $linhas);
        }

        return self::SUCCESS;
    }

    /**
     * Regra de ouro nº 7: dizer em voz alta contra qual banco isto vai rodar, antes de rodar.
     */
    private function confirmarAlvo(): bool
    {
        $conexao = config('database.default');
        $banco = config("database.connections.{$conexao}.database");

        $this->newLine();
        $this->line("  <fg=yellow>Alvo:</> conexão '<fg=white>{$conexao}</>', banco '<fg=white>{$banco}</>' — <fg=green>SOMENTE LEITURA</> (só rotas GET)");

        if (str_contains((string) $banco, 'test')) {
            $this->warn('  ⚠️  Este é o banco de TESTE (vazio). Os números não representam o volume real.');

            if (! $this->confirm('  Continuar mesmo assim?', false)) {
                return false;
            }
        }

        $this->newLine();

        return true;
    }

    /**
     * @return array<string, array{rota: string, params?: array<string, mixed>, perfis?: list<string>, partial?: list<string>}>
     */
    private function rotasSelecionadas(): array
    {
        $todas = config('perf.rotas_core', []);
        $filtro = trim((string) $this->option('rotas'));

        if ($filtro === '') {
            return $todas;
        }

        $querem = array_map('trim', explode(',', $filtro));

        return array_filter(
            $todas,
            fn (string $chave) => in_array($chave, $querem, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Escolha determinística: sempre o mesmo usuário entre execuções, senão dois runs
     * não são comparáveis (um vendedor com 283 clientes e outro com 3.000 medem coisas
     * diferentes). O id é impresso justamente para que a comparação seja auditável.
     */
    private function usuarioPara(string $perfil): ?User
    {
        return User::role($perfil)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /** @param array{perfis?: list<string>} $conf */
    private function perfilPodeAcessar(string $perfil, array $conf): bool
    {
        return ! isset($conf['perfis']) || in_array($perfil, $conf['perfis'], true);
    }

    /**
     * Mede a mesma rota de três jeitos, porque medem coisas diferentes:
     *  - html    → primeira pintura (documento completo)
     *  - inertia → navegação entre páginas (XHR, só o JSON)
     *  - partial → recarga parcial (só as props pedidas) — é o que prova o ganho de only:/defer()
     *
     * @param  array{rota: string, params?: array<string, mixed>, partial?: list<string>}  $conf
     * @return array<string, Medicao>
     */
    private function medirRota(User $usuario, array $conf, string $modo): array
    {
        $url = route($conf['rota'], $conf['params'] ?? [], false);

        /*
         * X-Inertia-Version é obrigatório: sem ele o Inertia responde 409 (version
         * mismatch) e o que seria medido é a página de erro, não a real.
         */
        $inertia = [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_VERSION' => $this->versaoInertia(),
        ];

        $variantes = [
            'html' => [],
            'inertia' => $inertia,
        ];

        if (! empty($conf['partial'])) {
            $variantes['partial'] = $inertia + [
                'HTTP_X_INERTIA_PARTIAL_DATA' => implode(',', $conf['partial']),
                // O componente é preenchido depois da primeira resposta Inertia.
            ];
        }

        $resultados = [];
        $componente = null;

        foreach ($variantes as $tipo => $headers) {
            if ($tipo === 'partial') {
                if (! $componente) {
                    continue;
                }
                $headers['HTTP_X_INERTIA_PARTIAL_COMPONENT'] = $componente;
            }

            $medicao = $this->medirComRepeticoes($usuario, $url, $headers, $modo);
            $resultados[$tipo] = $medicao;

            if ($tipo === 'inertia') {
                $componente = $this->componenteInertia($medicao);
            }
        }

        return $resultados;
    }

    /**
     * Roda N vezes e devolve a mediana por nº de queries. A 1ª é descartada: ela paga
     * autoload, resolução de container e outros custos de aquecimento que não se repetem.
     *
     * @param  array<string, string>  $headers
     */
    private function medirComRepeticoes(User $usuario, string $url, array $headers, string $modo): Medicao
    {
        $repeticoes = max(1, (int) $this->option('repeticoes'));
        $amostras = [];

        // Roda uma vez a mais que o pedido: a execução de índice 0 é só aquecimento
        // (autoload, resolução de container) e nunca entra na amostra.
        for ($i = 0; $i <= $repeticoes; $i++) {
            $medicao = $this->requisitar($usuario, $url, $headers, $modo);

            if ($i > 0) {
                $amostras[] = $medicao;
            }
        }

        usort($amostras, fn (Medicao $a, Medicao $b) => $a->queries <=> $b->queries ?: $a->msWall <=> $b->msWall);

        return $amostras[intdiv(count($amostras), 2)];
    }

    /** @param array<string, string> $headers */
    private function requisitar(User $usuario, string $url, array $headers, string $modo): Medicao
    {
        $executar = function () use ($usuario, $url, $headers): mixed {
            /*
             * Autentica sem passar por /login: o SessionGuard devolve o usuário já setado
             * sem consultar a sessão, então o middleware `auth` enxerga o usuário certo.
             * Precisa ser refeito a cada requisição porque o kernel reinicia o estado.
             */
            Auth::guard('web')->setUser($usuario);

            $kernel = app(Kernel::class);
            $request = Request::create($url, 'GET', [], [], [], $headers);
            $request->setUserResolver(fn () => $usuario);

            $resposta = $kernel->handle($request);
            $kernel->terminate($request, $resposta);

            return $resposta;
        };

        $medicao = $modo === 'frio'
            ? $this->comCacheFrio(fn () => ContadorDeQueries::medir($executar, (bool) $this->option('sql')))
            : ContadorDeQueries::medir($executar, (bool) $this->option('sql'));

        $status = $medicao->resultado?->getStatusCode();
        if ($status && $status >= 400) {
            $this->warn("    ⚠️  {$url} devolveu HTTP {$status} — a medição é da página de erro, não da real.");
        }

        return $medicao;
    }

    /**
     * ⚠️ NUNCA usar `cache:clear` para simular cache frio.
     *
     * Depois que o Redis entrar (Fase 1), o store de cache é compartilhado — e no ambiente
     * de produção um flush derrubaria sessão e fila de todos os usuários. Aqui trocamos o
     * driver por `array` só durante a medição: o efeito é o mesmo (nenhum acerto de cache)
     * sem tocar em nada que outra pessoa esteja usando.
     */
    private function comCacheFrio(Closure $acao): Medicao
    {
        $original = config('cache.default');

        config(['cache.default' => 'array']);
        Cache::purge('array');

        try {
            return $acao();
        } finally {
            config(['cache.default' => $original]);
            Cache::purge('array');
        }
    }

    /**
     * Versão dos assets que o Inertia usa pra detectar deploy novo. Vem do mesmo
     * middleware que a aplicação usa, então nunca diverge do que o navegador receberia.
     */
    private function versaoInertia(): string
    {
        return $this->versaoInertia ??= (string) app(\App\Http\Middleware\HandleInertiaRequests::class)
            ->version(Request::create('/', 'GET'));
    }

    private ?string $versaoInertia = null;

    private function componenteInertia(Medicao $medicao): ?string
    {
        $conteudo = $medicao->resultado?->getContent();

        if (! is_string($conteudo)) {
            return null;
        }

        $json = json_decode($conteudo, true);

        return is_array($json) ? ($json['component'] ?? null) : null;
    }

    /** @param list<array<string, mixed>> $linhas */
    private function renderTabela(array $linhas): void
    {
        $this->table(
            ['Perfil', 'Rota', 'Tipo', 'Cache', 'Queries', 'SQL ms', 'Payload KB', 'Mem MB', 'Wall ms*'],
            array_map(fn (array $l) => array_values($l), $linhas),
        );
    }

    /** @param list<array<string, mixed>> $linhas */
    private function renderForaDoOrcamento(array $linhas): void
    {
        $maxQueries = config('perf.orcamento.queries_max');
        $maxPayload = config('perf.orcamento.payload_kb_max');

        $estouros = array_filter(
            $linhas,
            fn (array $l) => $l['queries'] > $maxQueries || $l['payload_kb'] > $maxPayload,
        );

        if ($estouros === []) {
            $this->info("  ✓ Tudo dentro do orçamento ({$maxQueries} queries, {$maxPayload} KB).");

            return;
        }

        $this->newLine();
        $this->error('  Fora do orçamento:');

        foreach ($estouros as $l) {
            $motivos = [];
            if ($l['queries'] > $maxQueries) {
                $motivos[] = "{$l['queries']} queries (máx {$maxQueries})";
            }
            if ($l['payload_kb'] > $maxPayload) {
                $motivos[] = "{$l['payload_kb']} KB (máx {$maxPayload})";
            }

            $this->line("    <fg=red>✗</> {$l['perfil']} · {$l['rota']} · {$l['tipo']} ({$l['modo']}): ".implode(', ', $motivos));
        }
    }

    private ?Medicao $pior = null;

    private string $piorRotulo = '';

    /**
     * Mostra as queries mais repetidas da pior rota. É o diagnóstico direto de N+1:
     * o mesmo SQL aparecendo dezenas de vezes é a assinatura de uma relação sendo
     * carregada dentro de um laço.
     */
    private function renderSqlsRepetidos(): void
    {
        if (! $this->option('sql') || ! $this->pior) {
            return;
        }

        /*
         * Normaliza antes de contar: o eager loading do Laravel usa whereIntegerInRaw,
         * que INLINA os ids no SQL. Sem normalizar, 200 execuções da mesma consulta
         * aparecem como 200 SQLs distintos e o N+1 fica invisível justamente na
         * ferramenta feita pra achá-lo.
         */
        $normalizados = array_map(function (string $sql): string {
            $sql = preg_replace('/\s+/', ' ', $sql);
            $sql = preg_replace('/\d+/', '?', $sql);

            return preg_replace('/\?(\s*,\s*\?)+/', '?…', $sql);
        }, $this->pior->sqls);

        $contagem = array_count_values($normalizados);
        arsort($contagem);

        $this->newLine();
        $this->line("  <fg=cyan>Queries mais repetidas em {$this->piorRotulo}</> ({$this->pior->queries} no total):");

        foreach (array_slice($contagem, 0, 8, true) as $sql => $vezes) {
            $marcador = $vezes > 5 ? '<fg=red>' : '<fg=gray>';
            $this->line("    {$marcador}{$vezes}x</> ".mb_substr($sql, 0, 140));
        }
    }

    private function renderAvisoDeRuido(): void
    {
        $this->newLine();
        $this->line('  <fg=gray>* Wall ms é RUÍDO neste ambiente (Docker sobre WSL2, bind-mount, servidor single-process).</>');
        $this->line('  <fg=gray>  Use Queries e Payload para comparar antes/depois. Latência real só no loadtest e no ALB.</>');
    }

    /** @param list<array<string, mixed>> $linhas */
    private function salvarSnapshot(string $nome, array $linhas): void
    {
        $caminho = storage_path('app/perf/'.trim($nome).'.json');
        File::ensureDirectoryExists(dirname($caminho));
        File::put($caminho, json_encode([
            'gerado_em' => now()->toIso8601String(),
            'linhas' => $linhas,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("  Snapshot salvo em {$caminho}");
    }

    /** @param list<array<string, mixed>> $atuais */
    private function compararCom(string $nome, array $atuais): void
    {
        $caminho = storage_path('app/perf/'.trim($nome).'.json');

        if (! File::exists($caminho)) {
            $this->error("  Snapshot '{$nome}' não encontrado em {$caminho}.");

            return;
        }

        $anterior = json_decode(File::get($caminho), true)['linhas'] ?? [];
        $indexar = fn (array $l) => "{$l['perfil']}|{$l['rota']}|{$l['tipo']}|{$l['modo']}";
        $antes = collect($anterior)->keyBy($indexar);

        $this->newLine();
        $this->line("  <fg=cyan>Comparação com '{$nome}':</>");

        $diffs = [];
        foreach ($atuais as $l) {
            $a = $antes->get($indexar($l));
            if (! $a) {
                continue;
            }

            $delta = $l['queries'] - $a['queries'];
            if ($delta === 0) {
                continue;
            }

            $pct = $a['queries'] > 0 ? round($delta / $a['queries'] * 100) : 0;
            $cor = $delta < 0 ? 'green' : 'red';
            $sinal = $delta > 0 ? '+' : '';

            $diffs[] = "    <fg={$cor}>{$a['queries']} → {$l['queries']} ({$sinal}{$pct}%)</> · {$l['perfil']} · {$l['rota']} · {$l['tipo']} ({$l['modo']})";
        }

        if ($diffs === []) {
            $this->line('    Nenhuma mudança na contagem de queries.');

            return;
        }

        foreach ($diffs as $linha) {
            $this->line($linha);
        }
    }
}
