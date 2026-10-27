<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Segmento;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use Illuminate\Support\Carbon;

/**
 * Situação de um cliente da carteira: ativo/inativando/inativo e por quê.
 *
 * ⚠️ O status é o da CARTEIRA, que agrupa as filiais pelo código do cliente e usa a
 * compra mais recente entre elas (`CarteiraController::resumoDosCodigos`). Usar a data
 * só desta filial daria "inativo" no chat para um cliente que aparece "ativo" na tela —
 * exatamente o tipo de divergência que faz o usuário desconfiar dos dois.
 */
final class ResumoCliente implements Ferramenta
{
    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly ClienteStatusResolver $status,
    ) {}

    public function nome(): string
    {
        return 'resumo_cliente';
    }

    public function rotuloProgresso(): string
    {
        return 'Abrindo a ficha do cliente…';
    }

    public function descricao(): string
    {
        return 'Ficha resumida de um cliente da carteira, pelo id (vem de buscar_cliente): '
            .'status na carteira (ativo/inativando/inativo) com o motivo, última compra, '
            .'último contato registrado, segmento e pedidos em aberto. Use para "por que o '
            .'cliente está inativo" e "quando comprou pela última vez". Para valor faturado, '
            .'use numeros_do_cliente.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente_id' => ['type' => 'integer', 'description' => 'O id devolvido por buscar_cliente.'],
            ],
            'required' => ['cliente_id'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $cliente = Cliente::query()->find((int) ($entrada['cliente_id'] ?? 0));

        if ($cliente === null || ! $this->escopo->alcanca($contexto->user, $cliente->cod_vendedor)) {
            return ['encontrado' => false, 'mensagem' => 'Cliente não encontrado na carteira do usuário.'];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];

        $ultimaCompra = Cliente::query()
            ->where('cod_cliente', $cliente->cod_cliente)
            ->when($codigos !== null, fn ($q) => $q->whereIn('cod_vendedor', $codigos))
            ->max('data_ultima_compra');
        $ultimaCompra = $ultimaCompra ? Carbon::parse($ultimaCompra) : null;

        $status = $this->status->statusPara($ultimaCompra);

        $abertos = Pedido::query()
            ->naCarteiraAberta()
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('data_pedido')
            ->limit(5)
            ->get(['numero_pedido', 'data_pedido', 'valor_total', 'data_previsao_faturamento']);

        return [
            'encontrado' => true,
            'razao_social' => $cliente->razao_social,
            'nome_fantasia' => $cliente->nome_fantasia,
            'cnpj' => $cliente->cnpj,
            'codigo_totvs' => $cliente->codigoTotvs(),
            'cidade_uf' => trim(($cliente->municipio ?? '').' / '.($cliente->estado ?? ''), ' /') ?: null,
            'segmento' => $cliente->cod_segmento
                ? (Segmento::query()->where('codigo', $cliente->cod_segmento)->value('nome') ?? $cliente->cod_segmento)
                : null,
            'status_carteira' => $status,
            'ultima_compra' => Formato::data($ultimaCompra),
            'dias_sem_comprar' => $ultimaCompra ? (int) $ultimaCompra->diffInDays(today()) : null,
            'regra_do_status' => 'Ativo: comprou nos últimos '.ClienteStatusResolver::DIAS_ATIVO.' dias. '
                .'Inativando: entre '.ClienteStatusResolver::DIAS_ATIVO.' e '.ClienteStatusResolver::DIAS_INATIVANDO.' dias. '
                .'Inativo: mais de '.ClienteStatusResolver::DIAS_INATIVANDO.' dias ou nunca comprou. '
                .'Conta a compra mais recente entre todas as filiais do cliente.',
            'ultimo_contato' => $cliente->data_ultimo_contato
                ? Formato::data($cliente->data_ultimo_contato).' ('.($cliente->canal_ultimo_contato ?? 'canal não informado').')'
                : 'nenhum contato registrado no CRM',
            'pedidos_em_aberto' => $abertos->map(fn (Pedido $p) => [
                'numero' => $p->numero_pedido,
                'data' => Formato::data($p->data_pedido),
                'valor' => Formato::reais($p->valor_total),
                'previsao_faturamento' => Formato::data($p->data_previsao_faturamento),
            ])->all(),
        ];
    }
}
