<?php

namespace App\Jobs;

use App\Models\Exportacao;
use App\Services\Exportacao\GeradorDeExportacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Gera fora do ciclo da requisição a planilha que não cabe dentro dele.
 *
 * Vale para QUALQUER recurso do `CatalogoDeExportacoes`, não só a Carteira: quem escolhe
 * este caminho é o volume medido, não a tela. Ver `config/exportacoes.php`.
 *
 * O motivo original continua de pé: a Carteira completa leva ~95 s e ~540 MB, contra os
 * 60 s de idle timeout do ALB — 504 garantido, com o servidor ainda queimando memória
 * para produzir um arquivo que ninguém receberia. Aqui o usuário recebe resposta
 * imediata e é avisado pelo sino, com o Reverb entregando isso em tempo real.
 */
class GerarExportacaoJob implements ShouldQueue
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

    public function handle(GeradorDeExportacao $gerador): void
    {
        $exportacao = Exportacao::with('user')->find($this->exportacaoId);

        if (! $exportacao || ! $exportacao->user) {
            return; // registro (ou usuário) removido enquanto o job esperava na fila
        }

        $gerador->gerar($exportacao);

        // Notifica nos dois desfechos: sucesso avisa que o arquivo está lá, falha avisa
        // para tentar de novo. Silêncio deixaria o usuário esperando indefinidamente.
        $gerador->notificar($exportacao->refresh());
    }

    /**
     * Chamado quando o job estoura o timeout ou morre sem passar pelo catch do gerador —
     * caso em que o registro ficaria preso em "processando" para sempre.
     */
    public function failed(?Throwable $e): void
    {
        $exportacao = Exportacao::with('user')->find($this->exportacaoId);

        if (! $exportacao || ! $exportacao->processando()) {
            return;
        }

        $exportacao->update([
            'status' => Exportacao::STATUS_ERRO,
            'erro' => $e ? mb_substr($e->getMessage(), 0, 1000) : 'Job interrompido.',
        ]);

        app(GeradorDeExportacao::class)->notificar($exportacao);
    }
}
