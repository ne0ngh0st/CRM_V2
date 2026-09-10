<?php

namespace App\Services\Portal;

use App\Models\Orcamento;
use App\Models\PortalCliente;
use App\Models\PortalProduto;
use App\Models\PortalRepresentante;
use App\Models\PortalUsuario;
use App\Models\User;

/**
 * Traduz o que o CRM-V2 conhece (código do TOTVS) nos ids internos do Portal que o
 * `POST /v1/api/orders` exige. Lê SÓ do espelho local (`portal_*`) — nunca faz HTTP
 * no meio de um envio, senão a disponibilidade do Portal entraria no orçamento de
 * latência da Regra de ouro nº 9.
 *
 * ⚠️ Toda recusa aqui é deliberada: é MUITO melhor barrar com uma mensagem que o
 * vendedor entende do que mandar um id duvidoso e receber um `201` bonito para o
 * pedido errado.
 *
 * Ver docs/integracao-portal-pedidos.md §4.3 (o mapa) e §4.4 (por que a guarda existe).
 */
class PortalDeParaResolver
{
    /**
     * @return array{clientId:int, clientRepresentativeId:int, deliveryClientId:int, createdBy:int, produtos: array<string,int>}
     */
    public function resolver(Orcamento $orcamento): array
    {
        $portalCliente = $this->cliente($orcamento);
        $portalUsuario = $this->usuario($orcamento->user);

        return [
            'clientId' => $portalCliente->portal_id,
            /*
             * O CRM não modela endereço de entrega alternativo, então a entrega é
             * sempre no próprio cliente — que é exatamente o caso que a documentação
             * deles manda resolver mandando o mesmo valor.
             */
            'deliveryClientId' => $portalCliente->portal_id,
            'clientRepresentativeId' => $this->representante($portalCliente, $portalUsuario, $orcamento),
            'createdBy' => $portalUsuario->portal_id,
            'produtos' => $this->produtos($orcamento),
        ];
    }

    private function cliente(Orcamento $orcamento): PortalCliente
    {
        $cliente = $orcamento->cliente;

        if ($cliente === null) {
            throw new PortalPedidoInvalidoException(
                'Este orçamento não está vinculado a um cliente do TOTVS. '.
                'Orçamento de lead precisa que o cadastro do cliente seja concluído antes de virar pedido.'
            );
        }

        $portalCliente = PortalCliente::query()
            ->where('code', $cliente->cod_cliente)
            ->where('store', $cliente->loja)
            ->where('deleted', false)
            ->first();

        if ($portalCliente === null) {
            throw new PortalPedidoInvalidoException(
                "O cliente {$cliente->cod_cliente}/{$cliente->loja} não foi encontrado no Portal. ".
                'Se o cadastro é novo, aguarde a sincronização do de-para.'
            );
        }

        $this->conferirDocumento($cliente->cnpj, $portalCliente);

        return $portalCliente;
    }

    /**
     * 🚨 A GUARDA. Existe porque foi MEDIDO (10/09/2026) que há par `code`+`store` que
     * existe nos dois lados apontando para empresas DIFERENTES. Sem esta conferência, o
     * pedido nasceria para a empresa errada e nada acusaria: o `201` volta normal, com
     * um id válido, e o erro só apareceria na entrega.
     *
     * ⚠️ Documento AUSENTE no Portal também recusa. "Não deu para conferir" não é o
     * mesmo que "conferi e bate" — e aqui o custo de um falso positivo é uma mensagem
     * chata, enquanto o de um falso negativo é mercadoria no cliente errado.
     */
    private function conferirDocumento(?string $cnpjCrm, PortalCliente $portalCliente): void
    {
        $nosso = PortalCliente::apenasDigitos($cnpjCrm);
        $deles = PortalCliente::apenasDigitos($portalCliente->document);

        if ($nosso === '' || $deles === '') {
            throw new PortalPedidoInvalidoException(
                'Não foi possível conferir o CNPJ deste cliente contra o cadastro do Portal, '.
                'e o envio não é feito sem essa conferência. Avise o administrador.'
            );
        }

        if ($nosso !== $deles) {
            throw new PortalPedidoInvalidoException(
                'O cliente encontrado no Portal tem CNPJ diferente do cliente deste orçamento '.
                "(aqui {$nosso}, lá {$deles}). O envio foi bloqueado para não criar pedido para a empresa errada."
            );
        }
    }

    private function usuario(User $user): PortalUsuario
    {
        $codVendedor = $user->vendedorPerfil?->cod_vendedor;

        $portalUsuario = PortalUsuario::query()
            ->where('deleted', false)
            ->where(function ($q) use ($user, $codVendedor) {
                $q->where('email', $user->email);

                /*
                 * ⚠️ `seller_one` da tabela de clientes NÃO serve como chave (formato
                 * igual, valores divergentes — §4.4). O código de vendedor confiável é
                 * o `protheus_seller_code` da tabela de usuários.
                 */
                if (filled($codVendedor)) {
                    $q->orWhere('protheus_seller_code', $codVendedor);
                }
            })
            ->first();

        if ($portalUsuario === null) {
            throw new PortalPedidoInvalidoException(
                "O vendedor {$user->email} não tem usuário correspondente no Portal. ".
                'É cadastro do lado do Portal, não do CRM.'
            );
        }

        if (! $portalUsuario->ativo) {
            throw new PortalPedidoInvalidoException(
                "O usuário do vendedor {$user->email} está inativo no Portal e não pode responder por pedidos."
            );
        }

        return $portalUsuario;
    }

    /**
     * ⚠️ Falha comum e NÃO é bug nosso: se o vendedor que montou o orçamento não estiver
     * cadastrado como representante DAQUELE cliente no Portal, a API recusa com 404. A
     * mensagem precisa dizer isso, senão vira "o sistema não deixa enviar".
     */
    private function representante(PortalCliente $cliente, PortalUsuario $usuario, Orcamento $orcamento): int
    {
        $representante = PortalRepresentante::query()
            ->where('portal_cliente_id', $cliente->portal_id)
            ->where('portal_usuario_id', $usuario->portal_id)
            ->first();

        if ($representante === null) {
            $nome = $orcamento->user->display_name ?: $orcamento->user->name;

            throw new PortalPedidoInvalidoException(
                "{$nome} não está cadastrado como representante de {$cliente->razao_social} no Portal. ".
                'Sem esse vínculo o Portal recusa o pedido — peça o cadastro ao time do Portal.'
            );
        }

        return $representante->portal_id;
    }

    /**
     * @return array<string,int> cod_produto => id do produto no Portal
     */
    private function produtos(Orcamento $orcamento): array
    {
        $codigos = $orcamento->itens
            ->pluck('cod_produto')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->values();

        if ($codigos->isEmpty()) {
            return [];
        }

        $encontrados = PortalProduto::query()
            ->whereIn('code', $codigos)
            ->where('deleted', false)
            ->get();

        $faltando = $codigos->diff($encontrados->pluck('code'));

        if ($faltando->isNotEmpty()) {
            throw new PortalPedidoInvalidoException(
                'Estes produtos não foram encontrados no Portal: '.$faltando->implode(', ').'.'
            );
        }

        $this->conferirFatorDeConversao($orcamento, $encontrados->keyBy('code'));

        return $encontrados->pluck('portal_id', 'code')->all();
    }

    /**
     * ⚠️ Regra da API: quando o tipo de conversão é `D` e a unidade secundária é `CX`,
     * a quantidade precisa ser múltiplo EXATO do fator. Conferir aqui é o que troca um
     * `400` incompreensível por uma frase que diz quanto pedir.
     *
     * @param  \Illuminate\Support\Collection<string, PortalProduto>  $porCodigo
     */
    private function conferirFatorDeConversao(Orcamento $orcamento, $porCodigo): void
    {
        foreach ($orcamento->itens as $item) {
            $produto = $porCodigo->get(trim((string) $item->cod_produto));

            if ($produto === null || $produto->tipo_conversao !== 'D' || $produto->unidade_secundaria !== 'CX') {
                continue;
            }

            $fator = (float) $produto->fator_conversao;

            if ($fator <= 0) {
                throw new PortalPedidoInvalidoException(
                    "O produto {$produto->code} não tem fator de conversão cadastrado no Portal."
                );
            }

            if (fmod((float) $item->quantidade, $fator) !== 0.0) {
                throw new PortalPedidoInvalidoException(
                    "O produto {$produto->code} só pode ser pedido em múltiplos de ".
                    rtrim(rtrim(number_format($fator, 4, ',', ''), '0'), ',').
                    " (a quantidade pedida é {$item->quantidade})."
                );
            }
        }
    }
}
