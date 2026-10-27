<?php

namespace App\Services\Ia\Ferramentas;

use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;

/**
 * Os mesmos números do card "Pedidos que requerem atenção" do Painel.
 */
final class PedidosEmAtencao implements Ferramenta
{
    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly DashboardBlocos $blocos,
    ) {}

    public function nome(): string
    {
        return 'pedidos_em_atencao';
    }

    public function rotuloProgresso(): string
    {
        return 'Olhando os pedidos em aberto…';
    }

    public function descricao(): string
    {
        return 'Carteira de pedidos em aberto do escopo: quantidade, valor em aberto, quantos '
            .'estão atrasados (previsão de faturamento vencida), quantos vencem em 7 dias e o '
            .'valor em risco (atrasados + vencendo). Com incluir_lista true, traz os atrasados. '
            .'Use para "quanto está em aberto", "o que está atrasado", "valor em risco".';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'incluir_lista' => ['type' => 'boolean', 'description' => 'true para listar os pedidos atrasados.'],
            ],
            'required' => ['incluir_lista'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $bloco = $this->blocos->pedidosAtencao(ChaveEscopo::deCodVendedores($codigos), $codigos);

        $saida = [
            'escopo' => Formato::escopo($codigos, $contexto->user->getRoleNames()->first()),
            'em_aberto' => [
                'quantidade' => $bloco['totalAberto'],
                'valor' => Formato::reais($bloco['valorEmAberto']),
            ],
            'atrasados' => $bloco['atrasados'],
            'vencendo_em_7_dias' => $bloco['vencendo'],
            'valor_em_risco' => Formato::reais($bloco['valorEmRisco']),
        ];

        if ($entrada['incluir_lista'] ?? false) {
            $itens = $bloco['itens'] instanceof \Illuminate\Support\Collection
                ? $bloco['itens']->all()
                : (array) $bloco['itens'];

            $saida['atrasados_lista'] = array_map(fn (array $item) => [
                'numero' => $item['numero'],
                'cliente' => $item['cliente'],
                'valor' => Formato::reais($item['valorTotal']),
                'previsao' => $item['previsao'],
                'dias_de_atraso' => $item['diasAtraso'],
            ], $itens);
        }

        return $saida;
    }
}
