<?php

namespace App\Http\Controllers;

use App\Jobs\AtualizarDadosTotvsJob;
use App\Models\TotvsImportacao;
use App\Services\Totvs\AtualizadorTotvs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): Response
    {
        $this->autorizarAdmin($request);

        return Inertia::render('Atualizacoes/Index', [
            'frescor' => $this->frescor(),
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
        $hoje = now()->startOfDay();

        $contagens = app(AtualizadorTotvs::class)->contagens();

        $itens = [
            [
                'dominio' => 'Faturamento',
                'tabela' => 'faturamentos',
                'data' => DB::table('faturamentos')->max('data_emissao'),
                'linhas' => $contagens['faturamentos'],
                'relatorio' => '198 — FAT',
            ],
            [
                'dominio' => 'Pedidos',
                'tabela' => 'pedidos',
                'data' => DB::table('pedidos')->max('data_pedido'),
                'linhas' => $contagens['pedidos'],
                'relatorio' => '200 + 232',
            ],
        ];

        return array_map(function (array $item) use ($hoje) {
            $data = $item['data'] !== null ? \Illuminate\Support\Carbon::parse($item['data']) : null;

            return $item + [
                'dias' => $data?->startOfDay()->diffInDays($hoje),
            ];
        }, $itens);
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
