<?php

namespace App\Jobs;

use App\Models\Cotacao;
use App\Services\Cotacao\CotacaoRecusada;
use App\Services\Cotacao\CotacaoService;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Compara a lista do cliente com o estoque de revenda, fora da requisição.
 *
 * Notifica nos dois desfechos. ⚠️ A falha notifica TODA vez (sem referência de
 * idempotência): com dedupe, a segunda falha da mesma cotação sairia muda e a pessoa
 * concluiria que deu certo — mesma regra do envio ao Portal.
 */
class ProcessarCotacaoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 400;

    /** Uma tentativa: reprocessar sozinho repetiria um erro de leitura da planilha. */
    public int $tries = 1;

    public function __construct(
        public readonly int $cotacaoId,
    ) {}

    public function handle(CotacaoService $servico, NotificacaoService $notificacoes): void
    {
        $cotacao = Cotacao::with('user')->find($this->cotacaoId);

        if (! $cotacao || ! $cotacao->user || $cotacao->status !== Cotacao::STATUS_PROCESSANDO) {
            return;
        }

        try {
            $servico->processar($cotacao);
        } catch (CotacaoRecusada $e) {
            $this->falhar($cotacao, $e->getMessage(), $notificacoes);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->falhar($cotacao, 'Não foi possível comparar a lista agora. Tente de novo; se repetir, avise o pessoal de sistemas.', $notificacoes);

            return;
        }

        $notificacoes->notificar(
            $cotacao->user,
            'cotacao_pronta',
            'Cotação pronta: '.$cotacao->nome_arquivo,
            $cotacao->itens_total.' itens comparados com o estoque.',
            route('cotacoes.show', $cotacao, false),
            'cotacao',
            $cotacao->id,
        );
    }

    public function failed(?Throwable $e): void
    {
        $cotacao = Cotacao::with('user')->find($this->cotacaoId);

        if ($cotacao && $cotacao->user && $cotacao->status === Cotacao::STATUS_PROCESSANDO) {
            $this->falhar($cotacao, 'A comparação foi interrompida. Tente de novo.', app(NotificacaoService::class));
        }
    }

    private function falhar(Cotacao $cotacao, string $motivo, NotificacaoService $notificacoes): void
    {
        $cotacao->update(['status' => Cotacao::STATUS_FALHA, 'erro' => mb_substr($motivo, 0, 2000)]);

        $notificacoes->notificar(
            $cotacao->user,
            'cotacao_falhou',
            'Cotação não processada: '.$cotacao->nome_arquivo,
            $motivo,
            route('cotacoes.index', [], false),
        );
    }
}
