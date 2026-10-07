<?php

namespace App\Jobs;

use App\Models\Orcamento;
use App\Models\User;
use App\Services\Portal\GeradorDePedidoNoPortal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda ao Portal o corpo que `preparar()` já congelou.
 *
 * ⚠️ Vai para a fila porque é a ÚNICA parte do fluxo que depende da rede: o homolog
 * responde em ~500 ms, o que sozinho estoura o orçamento de escrita da Regra de ouro
 * nº 9. Tudo que pode falhar por causa NOSSA (de-para, produto, CNPJ) já foi validado
 * dentro da requisição e devolvido na hora.
 */
class EnviarPedidoAoPortalJob implements ShouldQueue
{
    use Queueable;

    /**
     * Só a falha de REDE chega aqui — recusa do Portal é tratada dentro do gerador e
     * não retenta, porque a mesma chave com o mesmo corpo daria a mesma resposta.
     */
    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * ⚠️ Carrega por ID, não pelo model serializado: entre o clique e o worker o
     * orçamento pode ter mudado, e o que vale é o estado do banco.
     */
    public function __construct(
        public readonly int $orcamentoId,
        /*
         * Quem clicou — recebe o resultado pelo sino. Opcional só para que um job já
         * enfileirado antes deste parâmetro existir continue rodando depois do deploy.
         */
        public readonly ?int $solicitanteId = null,
    ) {
    }

    public function handle(GeradorDePedidoNoPortal $gerador): void
    {
        $orcamento = Orcamento::find($this->orcamentoId);

        if ($orcamento === null) {
            return;
        }

        /*
         * Já tem pedido: outra tentativa venceu a corrida (ou a retentativa aconteceu
         * depois de um sucesso que a resposta não chegou a confirmar). Sair aqui evita
         * uma chamada inútil — a idempotência do Portal já cobriria, mas não custa.
         */
        if ($orcamento->foiEnviadoAoPortal()) {
            return;
        }

        $gerador->enviar($orcamento, $this->solicitante());
    }

    /**
     * Esgotou as tentativas sem resposta. Sem isto o orçamento ficava calado para
     * sempre: nem pedido, nem erro, e o vendedor sem saber se clica de novo.
     */
    public function failed(?\Throwable $e): void
    {
        $orcamento = Orcamento::find($this->orcamentoId);

        if ($orcamento !== null) {
            app(GeradorDePedidoNoPortal::class)->registrarFalhaDeRede($orcamento, $this->solicitante());
        }
    }

    private function solicitante(): ?User
    {
        return $this->solicitanteId !== null ? User::find($this->solicitanteId) : null;
    }
}
