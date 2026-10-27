<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Pedidos\StatusPedidoResolver;

/**
 * Status de um pedido pelo número.
 *
 * ⚠️ "Não encontrado" é a MESMA resposta para pedido inexistente e para pedido de outra
 * carteira. Dizer "existe, mas não é seu" já seria vazar que o colega vendeu para alguém.
 */
final class ConsultarPedido implements Ferramenta
{
    private const MAX_ITENS = 20;

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly StatusPedidoResolver $status,
    ) {}

    public function nome(): string
    {
        return 'consultar_pedido';
    }

    public function rotuloProgresso(): string
    {
        return 'Consultando o pedido…';
    }

    public function descricao(): string
    {
        return 'Consulta um pedido pelo número do TOTVS (ex.: 992086 ou A00063): situação '
            .'(em aberto ou faturado), etapa no TOTVS, último movimento, previsão de '
            .'faturamento, se está atrasado, cliente e itens. Só encontra pedidos da carteira '
            .'do usuário. Use sempre que o usuário citar um número de pedido.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'numero' => ['type' => 'string', 'description' => 'Número do pedido, como o usuário escreveu.'],
            ],
            'required' => ['numero'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $numero = mb_strtoupper(trim((string) ($entrada['numero'] ?? '')));
        $numero = ltrim($numero, '#');

        if ($numero === '') {
            return ['encontrado' => false, 'mensagem' => 'Informe o número do pedido.'];
        }

        $pedidos = Pedido::query()
            ->where('numero_pedido', $numero)
            ->with('cliente:id,razao_social,nome_fantasia,cod_cliente,loja')
            ->limit(5)
            ->get()
            ->filter(fn (Pedido $p) => $this->escopo->alcanca($contexto->user, $p->cod_vendedor))
            ->values();

        if ($pedidos->isEmpty()) {
            return [
                'encontrado' => false,
                'mensagem' => "Nenhum pedido {$numero} na carteira do usuário. Ou o número está "
                    .'diferente, ou o pedido é de outra carteira, ou ainda não chegou ao CRM '
                    .'(pedido novo só aparece depois que o relatório de pedidos do TOTVS é carregado).',
            ];
        }

        return [
            'encontrado' => true,
            'pedidos' => $pedidos->map(fn (Pedido $p) => $this->descrever($p))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function descrever(Pedido $p): array
    {
        $emAberto = $p->data_faturamento === null;
        $status = $this->status->paraTela($p);
        $itens = $p->itens()->orderBy('id')->get();

        return [
            'numero' => $p->numero_pedido,
            'filial' => $p->filial,
            'cliente' => $p->cliente ? [
                'razao_social' => $p->cliente->razao_social,
                'codigo_totvs' => $p->cliente->codigoTotvs(),
            ] : null,
            'data_pedido' => Formato::data($p->data_pedido),
            'valor_total' => Formato::reais($p->valor_total),
            'situacao' => match (true) {
                ! $emAberto => 'faturado em '.Formato::data($p->data_faturamento),
                (bool) $p->fora_do_200 => 'saiu do relatório de pedidos em aberto do TOTVS e ainda não tem nota no CRM '
                    .'(faturado há pouco, ou cancelado)',
                default => 'em aberto',
            },
            'etapa_totvs' => $emAberto ? $status['statusRotulo'] : null,
            'ultimo_movimento_totvs' => $status['movimento'],
            'ultimo_movimento_em' => $status['movimentoEm'],
            'previsao_faturamento' => Formato::data($p->data_previsao_faturamento),
            'atrasado' => $emAberto && $p->data_previsao_faturamento !== null && $p->data_previsao_faturamento->lt(today()),
            'entrega_prevista' => Formato::data($p->data_entrega_prevista),
            'condicao_pagamento' => $p->condicao_pagamento,
            'carga' => $p->carga,
            'total_itens' => $itens->count(),
            'itens' => $itens->take(self::MAX_ITENS)->map(fn (PedidoItem $i) => [
                'codigo' => $i->cod_produto,
                'descricao' => $i->descricao,
                'quantidade' => (float) $i->quantidade,
                'quantidade_liberada' => $i->quantidade_liberada === null ? null : (float) $i->quantidade_liberada,
                'valor_total' => Formato::reais($i->valor_total),
                'nota_fiscal' => $i->nota_fiscal,
            ])->values()->all(),
        ];
    }
}
