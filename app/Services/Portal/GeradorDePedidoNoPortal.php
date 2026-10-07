<?php

namespace App\Services\Portal;

use App\Models\Orcamento;
use App\Models\User;
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
     *
     * `$solicitante` é quem clicou em "Transformar em pedido" — quase sempre um admin, e
     * quase nunca o dono do orçamento. Ver avisar() para quem recebe o quê.
     */
    public function enviar(Orcamento $orcamento, ?User $solicitante = null): void
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

            $this->avisar($orcamento, sucesso: false, detalhe: $this->explicarRecusa($e->getMessage(), $corpo), solicitante: $solicitante);

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

        $this->avisar($orcamento, sucesso: true, detalhe: $detalhe !== '' ? $detalhe : null, solicitante: $solicitante);
    }

    /**
     * O job esgotou as tentativas sem resposta do Portal. O pedido PODE ter sido criado
     * — por isso a chave e o corpo ficam como estão: clicar de novo reenvia a mesma
     * requisição, e a idempotência deles devolve o pedido se ele existir.
     */
    public function registrarFalhaDeRede(Orcamento $orcamento, ?User $solicitante = null): void
    {
        if ($orcamento->foiEnviadoAoPortal()) {
            return;
        }

        $mensagem = 'O Portal não respondeu. Não se sabe se o pedido foi criado: '.
            'tente de novo — o reenvio usa a mesma chave e não duplica o pedido.';

        $orcamento->forceFill(['portal_erro' => $mensagem])->save();

        $this->avisar($orcamento, sucesso: false, detalhe: $mensagem, solicitante: $solicitante);
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

    /**
     * A data que o ERP sugere quando recusa a pedida ("A data de entrega desejada no
     * pedido é inválida. A próxima data válida seria 2026-10-26."), ou null se a recusa
     * não foi de data. Lida da mensagem LITERAL guardada em `portal_erro` — é o único
     * lugar onde a API devolve essa data.
     */
    public static function dataSugeridaNaRecusa(?string $erro): ?string
    {
        if ($erro === null || ! str_contains(mb_strtolower($erro), 'data de entrega')) {
            return null;
        }

        return preg_match('/(\d{4}-\d{2}-\d{2})/', $erro, $m) ? $m[1] : null;
    }

    /**
     * O aviso de recusa de data diz o que aconteceu com as datas do próprio pedido, em
     * vez de repetir a frase do ERP: quem lê precisa saber que a data que ELE digitou
     * não vale e qual vale. As outras recusas seguem literais — a mensagem do Portal
     * ("Vendedor não encontrado") diz mais do que qualquer tradução nossa.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function explicarRecusa(string $mensagem, array $corpo): string
    {
        $sugerida = $this->dataBr(self::dataSugeridaNaRecusa($mensagem));

        if ($sugerida === null) {
            return $mensagem;
        }

        $pedida = $this->dataBr($corpo['deliveryTime'] ?? null);

        return ($pedida !== null
            ? "O Protheus não aceita entrega em {$pedida}."
            : 'O Protheus não aceitou a data de entrega.')
            ." A primeira data possível é {$sugerida}: abra o orçamento e transforme em pedido de novo com ela.";
    }

    private function dataBr(mixed $data): ?string
    {
        if (! is_string($data) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $data)) {
            return null;
        }

        return substr($data, 8, 2).'/'.substr($data, 5, 2).'/'.substr($data, 0, 4);
    }

    /**
     * Quem recebe o quê (2026-10-07, depois do primeiro envio real em produção):
     *
     *  - **Pedido criado** → o dono do orçamento E quem clicou. O vendedor precisa saber
     *    que o orçamento dele virou pedido; quem clicou precisa do número.
     *  - **Recusa ou sem resposta** → só quem clicou: é quem pode corrigir e reenviar. No
     *    primeiro envio real o admin clicou, o Portal recusou ("Vendedor não encontrado"),
     *    e o aviso de erro foi parar no sino da VENDEDORA — que não tinha feito nada —,
     *    enquanto o admin ficou sem saber o resultado.
     *  - **Sem solicitante** (job enfileirado antes deste parâmetro existir) → o dono,
     *    como era antes.
     */
    private function avisar(Orcamento $orcamento, bool $sucesso, ?string $detalhe, ?User $solicitante = null): void
    {
        $destinatarios = match (true) {
            $solicitante === null => [$orcamento->user],
            $sucesso => [$orcamento->user, $solicitante],
            default => [$solicitante],
        };

        foreach (collect($destinatarios)->filter()->unique('id') as $destinatario) {
            $this->avisarUm($orcamento, $destinatario, $sucesso, $detalhe);
        }
    }

    private function avisarUm(Orcamento $orcamento, User $destinatario, bool $sucesso, ?string $detalhe): void
    {
        /*
         * Quem clicou em nome de outro vê de quem é o orçamento: "#2880" sozinho não diz
         * nada a quem transforma orçamentos da equipe inteira.
         */
        $deOutro = $destinatario->id !== $orcamento->user_id && $orcamento->user !== null
            ? ' ('.($orcamento->user->display_name ?: $orcamento->user->name).')'
            : '';

        $titulo = $sucesso
            ? "Orçamento #{$orcamento->id}{$deOutro} virou pedido no Portal"
            : "Orçamento #{$orcamento->id}{$deOutro} não virou pedido";

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
            destinatario: $destinatario,
            tipo: $sucesso ? 'portal_pedido_criado' : 'portal_pedido_erro',
            titulo: $titulo,
            mensagem: $mensagem,
            link: route('orcamentos.index'),
            referenciaTipo: $sucesso ? 'orcamento' : null,
            referenciaId: $sucesso ? $orcamento->id : null,
        );
    }
}
