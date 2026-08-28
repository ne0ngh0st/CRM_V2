<?php

namespace App\Jobs;

use App\Exports\CarteiraExport;
use App\Models\Exportacao;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Notificacao\NotificacaoService;
use App\Support\Uploads\Disco;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Gera a planilha da Carteira fora do ciclo da requisição.
 *
 * O export completo leva ~95 s e ~540 MB — mais que o idle timeout de 60 s do ALB. Aqui
 * o usuário recebe resposta imediata ("estamos preparando") e é avisado pelo sino quando
 * o arquivo fica pronto, com o Reverb já entregando isso em tempo real.
 *
 * ⚠️ COMO A QUERY ATRAVESSA A FILA: pelos FILTROS, não pelo Builder nem pelos IDs.
 *
 * Um Builder do Eloquent não é serializável de forma confiável. Serializar os IDs
 * resolvidos seria correto, mas no escopo admin isso é uma lista de 90 mil inteiros —
 * ~700 KB de payload no Redis e um `whereIn` gigantesco no SQL.
 *
 * A saída é guardar os filtros (poucos bytes) e reconstruir a MESMA query da tela aqui,
 * chamando `CarteiraController::listaQuery()` com uma Request sintética. Isso mantém uma
 * fonte só para "o que a Carteira lista" (Regra de ouro nº 8): se um filtro novo entrar
 * na tela amanhã, o export acompanha sem ninguém precisar lembrar de duplicá-lo.
 */
class GerarExportacaoCarteiraJob implements ShouldQueue
{
    use Queueable;

    /** Gerar 90 mil linhas leva ~95 s; o teto dá folga sem deixar um job travado para sempre. */
    public int $timeout = 600;

    /**
     * Uma tentativa só. Reexecutar um export de 540 MB automaticamente transformaria uma
     * falha pontual (memória, disco cheio) em pressão repetida sobre o mesmo recurso.
     */
    public int $tries = 1;

    public function __construct(
        private readonly int $exportacaoId,
    ) {}

    public function handle(NotificacaoService $notificacoes, ClienteStatusResolver $statusResolver): void
    {
        $exportacao = Exportacao::with('user')->find($this->exportacaoId);

        if (! $exportacao || ! $exportacao->user) {
            return; // registro (ou usuário) removido enquanto o job esperava na fila
        }

        try {
            // O worker tem seu próprio limite de memória, independente do PHP-FPM.
            ini_set('memory_limit', '1024M');

            $nome = 'carteira-'.now()->format('Y-m-d-His').'.xlsx';
            $caminho = "exports/{$exportacao->id}/{$nome}";

            $query = $this->queryDaTela($exportacao);
            $linhas = (clone $query)->count();

            Excel::store(new CarteiraExport($query, $statusResolver), $caminho, Disco::nomeExports());

            $exportacao->update([
                'status' => Exportacao::STATUS_PRONTO,
                'caminho' => $caminho,
                'nome_arquivo' => $nome,
                'linhas' => $linhas,
                // Descartável e pesado: sem prazo, o disco cresce para sempre.
                'expira_em' => now()->addDays(7),
            ]);

            $notificacoes->notificar(
                destinatario: $exportacao->user,
                tipo: 'exportacao_pronta',
                titulo: 'Planilha da Carteira pronta',
                mensagem: number_format($linhas, 0, ',', '.').' clientes. Disponível por 7 dias.',
                link: route('exportacoes.download', $exportacao->id, false),
                referenciaTipo: 'exportacao',
                referenciaId: $exportacao->id,
            );
        } catch (Throwable $e) {
            $exportacao->update([
                'status' => Exportacao::STATUS_ERRO,
                'erro' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Falha ao gerar exportação da Carteira', [
                'exportacao_id' => $exportacao->id,
                'erro' => $e->getMessage(),
            ]);

            // Falhar em silêncio seria pior que falhar: o usuário ficaria esperando um
            // arquivo que nunca vem, sem saber que precisa tentar de novo.
            $notificacoes->notificar(
                destinatario: $exportacao->user,
                tipo: 'exportacao_erro',
                titulo: 'Não foi possível gerar a planilha',
                mensagem: 'Tente novamente. Se persistir, aplique um filtro para reduzir o volume.',
                referenciaTipo: 'exportacao',
                referenciaId: $exportacao->id,
            );
        }
    }

    /**
     * Reconstrói exatamente a query que a tela estava mostrando.
     *
     * ⚠️ O escopo por perfil vem do usuário DONO da exportação, não de quem estiver
     * logado — o job roda sem sessão. Sem isto, um export pedido por um vendedor poderia
     * ser gerado com escopo vazio (ou pior, amplo demais).
     */
    private function queryDaTela(Exportacao $exportacao): \Illuminate\Database\Eloquent\Builder
    {
        $request = \Illuminate\Http\Request::create('/carteira', 'GET', $exportacao->filtros ?? []);
        $request->setUserResolver(fn () => $exportacao->user);

        return app(\App\Http\Controllers\CarteiraController::class)->listaQuery($request);
    }

    /**
     * Chamado quando o job estoura o timeout ou morre sem passar pelo catch — caso em que
     * o registro ficaria preso em "processando" para sempre.
     */
    public function failed(?Throwable $e): void
    {
        Exportacao::where('id', $this->exportacaoId)
            ->where('status', Exportacao::STATUS_PROCESSANDO)
            ->update([
                'status' => Exportacao::STATUS_ERRO,
                'erro' => $e ? mb_substr($e->getMessage(), 0, 1000) : 'Job interrompido.',
            ]);
    }
}
