<?php

namespace App\Services\Portal;

use App\Models\Orcamento;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Support\Str;

/**
 * O ÚNICO lugar que escreve as colunas `orcamentos.portal_*` (Regra de ouro nº 8).
 *
 * O trabalho é dividido em dois tempos de propósito:
 *
 *  - **preparar()** roda DENTRO da requisição. É tudo local (montagem do payload a
 *    partir do próprio orçamento), custa milissegundos, e é onde moram os erros que o
 *    vendedor precisa ver na hora: cliente sem vínculo, vendedor sem código, item de
 *    etiqueta sem produto. Devolver isso pelo sino seria cruel.
 *
 *  - **enviar()** roda NA FILA. É a única parte que depende da rede, e o homolog
 *    responde em ~500 ms — sozinho já estoura o orçamento de 500 ms da Regra de ouro
 *    nº 9 para ação de escrita.
 */
class GeradorDePedidoNoPortal
{
    public function __construct(
        private readonly PortalPedidoPayload $payload,
        private readonly PortalPedidoClient $client,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * Valida e congela o que será enviado. Lança PortalPedidoInvalidoException quando o
     * orçamento não pode virar pedido — e nesse caso nada é gravado.
     *
     * Devolve `true` quando REAPROVEITOU o corpo de uma tentativa anterior de resultado
     * incerto (ver abaixo) — nesse caso a data e a transportadora informadas agora são
     * ignoradas, e quem chama precisa dizer isso a quem clicou.
     */
    public function preparar(Orcamento $orcamento, string $dataEntrega, ?string $transportadora = null): bool
    {
        /*
         * 🚨 Chave presente = existe uma tentativa cujo resultado NÃO se sabe (ainda na
         * fila, ou o Portal não respondeu). Ela pode ter criado o pedido. O contrato
         * deles é "reenvie a requisição IDÊNTICA com a mesma chave": então o corpo
         * congelado vai de novo, literal. Remontá-lo com a data nova daria 409 com a
         * mesma chave — ou, com chave nova, um SEGUNDO pedido.
         *
         * Recusa (4xx) não cai aqui: ela garante que nada foi gravado, e por isso
         * `enviar()` apaga a chave — é o que libera escolher outra data depois de o ERP
         * recusar a primeira.
         */
        if ($orcamento->temEnvioIncertoAoPortal()) {
            $orcamento->forceFill(['portal_erro' => null])->save();

            return true;
        }

        $orcamento->loadMissing(['itens', 'cliente', 'user.vendedorPerfil']);

        $corpo = $this->payload->montar($orcamento, $dataEntrega, $transportadora);

        /*
         * 🚨 A chave nasce AQUI, persistida, ANTES de qualquer envio — e é reusada em
         * toda retentativa. Gerar chave nova na hora do envio é o único caminho que
         * ainda duplica pedido no Portal.
         */
        $orcamento->forceFill([
            'portal_idempotency_key' => 'crmv2-orc-'.$orcamento->id.'-'.Str::uuid(),
            'portal_payload' => $corpo,
            'portal_resposta' => null,
            'portal_erro' => null,
        ])->save();

        return false;
    }

    /**
     * Envia o corpo já congelado. Só deve ser chamado depois de preparar().
     */
    public function enviar(Orcamento $orcamento): void
    {
        $corpo = $orcamento->portal_payload;
        $chave = $orcamento->portal_idempotency_key;

        if (! is_array($corpo) || ! $chave) {
            throw new PortalPedidoInvalidoException('O envio não foi preparado.');
        }

        try {
            $resultado = $this->client->criarPedido($corpo, $chave);
        } catch (PortalPedidoRecusadoException $e) {
            /*
             * Recusa é terminal: guarda o motivo DELES, literal, e avisa quem pediu.
             * Não retenta — a mesma chave com o mesmo corpo daria a mesma resposta.
             *
             * ⚠️ E APAGA A CHAVE. A API garante que erro significa nada gravado, então
             * não há pedido a proteger — e o caso mais comum de recusa agora é o ERP
             * rejeitar a data de entrega (a mensagem traz a próxima data válida). A
             * próxima tentativa vai com OUTRA data, ou seja, outro corpo: com a chave
             * antiga seria 409 de chave reutilizada.
             */
            $orcamento->forceFill([
                'portal_erro' => $e->getMessage(),
                'portal_enviado_em' => null,
                'portal_idempotency_key' => null,
            ])->save();

            $this->avisar($orcamento, sucesso: false, detalhe: $e->getMessage());

            return;
        }

        $orcamento->forceFill([
            'portal_pedido_id' => $resultado['id'],
            'portal_resposta' => $resultado['dados'],
            'portal_enviado_em' => now(),
            'portal_erro' => null,
        ])->save();

        /*
         * `replay` significa que o pedido já existia e a chave só o devolveu — não é
         * erro nem duplicata. Vale distinguir na mensagem para ninguém achar que
         * apertou o botão duas vezes e criou dois pedidos.
         */
        $detalhe = trim($this->resumoDoErp($corpo, $resultado['dados']).' '.($resultado['replay']
            ? 'O pedido já havia sido criado; o Portal devolveu o mesmo número.'
            : ''));

        $this->avisar($orcamento, sucesso: true, detalhe: $detalhe !== '' ? $detalhe : null);
    }

    /**
     * O job esgotou as tentativas sem resposta do Portal. O pedido PODE ter sido criado
     * — por isso a chave e o corpo ficam como estão: clicar de novo reenvia a mesma
     * requisição, e a idempotência deles devolve o pedido se ele existir.
     */
    public function registrarFalhaDeRede(Orcamento $orcamento): void
    {
        if ($orcamento->foiEnviadoAoPortal()) {
            return;
        }

        $mensagem = 'O Portal não respondeu. Não se sabe se o pedido foi criado: '.
            'tente de novo — o reenvio usa a mesma chave e não duplica o pedido.';

        $orcamento->forceFill(['portal_erro' => $mensagem])->save();

        $this->avisar($orcamento, sucesso: false, detalhe: $mensagem);
    }

    /**
     * "Aguardando aprovação, entrega prevista 17/10/2026 (pedida para 15/10/2026)…".
     *
     * ⚠️ A data que VALE é a da resposta. O ERP ajusta a data pedida sem erro e sem
     * aviso — o 201 volta normal —, então dizer só "pedido criado" deixaria o vendedor
     * prometendo ao cliente a data que ele mesmo digitou.
     *
     * @param  array<string, mixed>  $corpo
     * @param  array<string, mixed>  $dados
     */
    private function resumoDoErp(array $corpo, array $dados): string
    {
        $partes = [];

        if (($dados['status'] ?? null) === 'AWAITING_APPROVAL') {
            $partes[] = 'Está na fila de aprovação.';
        }

        $entrega = $this->dataBr($dados['deliveryTime'] ?? null);
        $pedida = $this->dataBr($corpo['deliveryTime'] ?? null);

        if ($entrega !== null) {
            $partes[] = $entrega !== $pedida && $pedida !== null
                ? "Entrega prevista para {$entrega} (o ERP ajustou a data pedida, {$pedida})."
                : "Entrega prevista para {$entrega}.";
        }

        if (($faturamento = $this->dataBr($dados['expectedBillingDate'] ?? null)) !== null) {
            $partes[] = "Faturamento previsto para {$faturamento}.";
        }

        return implode(' ', $partes);
    }

    private function dataBr(mixed $data): ?string
    {
        if (! is_string($data) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $data)) {
            return null;
        }

        return substr($data, 8, 2).'/'.substr($data, 5, 2).'/'.substr($data, 0, 4);
    }

    private function avisar(Orcamento $orcamento, bool $sucesso, ?string $detalhe): void
    {
        $titulo = $sucesso
            ? "Orçamento #{$orcamento->id} virou pedido no Portal"
            : "Orçamento #{$orcamento->id} não virou pedido";

        $mensagem = $sucesso
            ? trim("Pedido nº {$orcamento->portal_pedido_id} criado no Portal. ".($detalhe ?? ''))
            : (string) $detalhe;

        /*
         * ⚠️ `referenciaTipo`/`referenciaId` só no SUCESSO. O NotificacaoService usa
         * esse par como chave de idempotência (existe para os jobs diários não
         * duplicarem), e no caso de ERRO isso seria pior que inútil: a segunda
         * tentativa que falhasse ficaria MUDA, e o vendedor concluiria que deu certo.
         * Pedido criado acontece uma vez só; erro pode acontecer várias, e cada uma
         * precisa aparecer.
         */
        $this->notificacoes->notificar(
            destinatario: $orcamento->user,
            tipo: $sucesso ? 'portal_pedido_criado' : 'portal_pedido_erro',
            titulo: $titulo,
            mensagem: $mensagem,
            link: route('orcamentos.index'),
            referenciaTipo: $sucesso ? 'orcamento' : null,
            referenciaId: $sucesso ? $orcamento->id : null,
        );
    }
}
