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
 *  - **preparar()** roda DENTRO da requisição. É tudo local (de-para + montagem do
 *    payload), custa milissegundos, e é onde moram os erros que o vendedor precisa ver
 *    na hora: cliente sem vínculo, produto fora do catálogo, CNPJ divergente,
 *    representante não cadastrado. Devolver isso pelo sino seria cruel.
 *
 *  - **enviar()** roda NA FILA. É a única parte que depende da rede, e o homolog
 *    responde em ~500 ms — sozinho já estoura o orçamento de 500 ms da Regra de ouro
 *    nº 9 para ação de escrita.
 */
class GeradorDePedidoNoPortal
{
    public function __construct(
        private readonly PortalDeParaResolver $dePara,
        private readonly PortalPedidoPayload $payload,
        private readonly PortalPedidoClient $client,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * Valida e congela o que será enviado. Lança PortalPedidoInvalidoException quando o
     * orçamento não pode virar pedido — e nesse caso nada é gravado.
     */
    public function preparar(Orcamento $orcamento): void
    {
        $orcamento->loadMissing(['itens', 'cliente', 'user.vendedorPerfil']);

        $ids = $this->dePara->resolver($orcamento);
        $corpo = $this->payload->montar($orcamento, $ids);

        /*
         * 🚨 A chave nasce AQUI, persistida, ANTES de qualquer envio — e é reusada em
         * toda retentativa. Gerar chave nova na hora do envio é o único caminho que
         * ainda duplica pedido no Portal.
         *
         * Quando já existe, é mantida: significa que houve tentativa anterior, e trocar
         * a chave transformaria uma retentativa segura num pedido novo.
         */
        $orcamento->forceFill([
            'portal_idempotency_key' => $orcamento->portal_idempotency_key ?: 'crmv2-orc-'.$orcamento->id.'-'.Str::uuid(),
            'portal_payload' => $corpo,
            'portal_erro' => null,
        ])->save();
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
             */
            $orcamento->forceFill([
                'portal_erro' => $e->getMessage(),
                'portal_enviado_em' => null,
            ])->save();

            $this->avisar($orcamento, sucesso: false, detalhe: $e->getMessage());

            return;
        }

        $orcamento->forceFill([
            'portal_pedido_id' => $resultado['id'],
            'portal_enviado_em' => now(),
            'portal_erro' => null,
        ])->save();

        /*
         * `replay` significa que o pedido já existia e a chave só o devolveu — não é
         * erro nem duplicata. Vale distinguir na mensagem para ninguém achar que
         * apertou o botão duas vezes e criou dois pedidos.
         */
        $this->avisar($orcamento, sucesso: true, detalhe: $resultado['replay']
            ? 'O pedido já havia sido criado; o Portal devolveu o mesmo número.'
            : null);
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
