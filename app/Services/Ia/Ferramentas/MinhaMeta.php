<?php

namespace App\Services\Ia\Ferramentas;

use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Metas\MetaRankingResolver;
use Carbon\Carbon;

/**
 * Meta × realizado do escopo do usuário — o mesmo número do gauge do Painel.
 *
 * Venda e faturamento juntos, porque é a confusão mais comum: a meta de venda mede
 * PEDIDO emitido; a de faturamento, NOTA emitida, que sai dias depois.
 */
final class MinhaMeta implements Ferramenta
{
    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly MetaRankingResolver $metas,
    ) {}

    public function nome(): string
    {
        return 'minha_meta';
    }

    public function rotuloProgresso(): string
    {
        return 'Calculando meta e realizado…';
    }

    public function descricao(): string
    {
        return 'Meta e realizado do mês (venda = pedidos emitidos; faturamento = notas '
            .'emitidas), no escopo do usuário: a própria carteira, a equipe do supervisor ou a '
            .'empresa para admin/diretor. Mês e ano nulos = mês corrente. O realizado vai até '
            .'o último dia fechado (ontem; na segunda, sexta).';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ano' => ['type' => ['integer', 'null'], 'description' => 'Ano, ou null para o corrente.'],
                'mes' => ['type' => ['integer', 'null'], 'description' => 'Mês 1-12, ou null para o corrente.'],
            ],
            'required' => ['ano', 'mes'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $ano = (int) ($entrada['ano'] ?? 0) ?: (int) now()->year;
        $mes = (int) ($entrada['mes'] ?? 0) ?: (int) now()->month;

        if ($mes < 1 || $mes > 12 || $ano < 2018 || $ano > (int) now()->year + 1) {
            return ['erro' => 'Mês ou ano inválido.'];
        }

        $escopo = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $codigos = $this->metas->codigosDoEscopo($escopo);
        [$inicio, $fim] = $this->metas->intervaloDatas($ano, $mes, $mes);

        $saida = [
            'periodo' => sprintf('%02d/%d', $mes, $ano),
            'escopo' => Formato::escopo($escopo, $contexto->user->getRoleNames()->first()),
            'realizado_ate' => $fim >= $inicio ? Formato::data(Carbon::parse($fim)) : 'mês ainda sem dia fechado',
        ];

        foreach (MetaRankingResolver::TIPOS as $tipo) {
            $r = $this->metas->metaVsRealizado($codigos, $ano, $mes, $mes, $tipo);
            $saida[$tipo] = [
                'meta' => Formato::reais($r['meta']),
                'realizado' => Formato::reais($r['realizado']),
                'percentual' => $r['meta'] > 0 ? $r['percentual'].'%' : 'sem meta cadastrada',
                // Sem meta, "meta atingida" seria mentira com cara de boa notícia.
                'falta' => match (true) {
                    $r['meta'] <= 0 => null,
                    $r['meta'] > $r['realizado'] => Formato::reais($r['meta'] - $r['realizado']),
                    default => 'meta atingida',
                },
            ];
        }

        return $saida;
    }
}
