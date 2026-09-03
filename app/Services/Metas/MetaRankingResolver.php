<?php

namespace App\Services\Metas;

use App\Models\Faturamento;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ranking meta × realizado em batch (sem N+1).
 *
 * Realizado faturamento = SUM(faturamentos.valor_total) no período.
 * Realizado venda = SUM(pedidos.valor_total) por data_pedido (mesma base da Home "pedidos emitidos").
 * Mês corrente: realizado até D-1 (segunda → D-3), alinhado ao legado gestao_metas.
 */
class MetaRankingResolver
{
    /**
     * Agregado único (Home / gauges): meta vs faturamento no intervalo de meses.
     * Universo alinhado ao ranking: só códigos de vendedor/representante ativos.
     *
     * @param  array<string>|null  $codVendedores  null = empresa (vendedores ativos); [] = vazio
     * @return array{meta: float, faturamento: float, percentual: float}
     */
    public function metaVsFaturamento(?array $codVendedores, int $ano, int $mesInicio, int $mesFim): array
    {
        if ($codVendedores !== null && $codVendedores === []) {
            return ['meta' => 0.0, 'faturamento' => 0.0, 'percentual' => 0.0];
        }

        // null → mesmos códigos do ranking (ativos), evita % da Home divergir de /metas.
        if ($codVendedores === null) {
            $codVendedores = $this->usuariosDoEscopo(null)
                ->pluck('vendedorPerfil.cod_vendedor')
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($codVendedores === []) {
                return ['meta' => 0.0, 'faturamento' => 0.0, 'percentual' => 0.0];
            }
        }

        [$inicio, $fim] = $this->intervaloDatas($ano, $mesInicio, $mesFim);

        $meta = (float) MetaMensal::query()
            ->where('ano', $ano)
            ->whereBetween('mes', [$mesInicio, $mesFim])
            ->where('tipo', 'faturamento')
            ->whereIn('cod_vendedor', $codVendedores)
            ->sum('valor_meta');

        $faturamento = 0.0;
        if ($fim >= $inicio) {
            $faturamento = (float) Faturamento::query()
                ->whereBetween('data_emissao', [$inicio, $fim])
                ->whereIn('cod_vendedor', $codVendedores)
                ->sum('valor_total');
        }

        return [
            'meta' => $meta,
            'faturamento' => $faturamento,
            'percentual' => $meta > 0 ? round(($faturamento / $meta) * 100, 1) : 0.0,
        ];
    }

    /**
     * Ranking por usuário (vendedor/representante ativo com código) no escopo.
     *
     * @param  array<string>|null  $codVendedores
     * @return array{
     *     linhas: list<array<string, mixed>>,
     *     totais: array<string, float|int>,
     *     kpis: array<string, int>,
     *     periodo: array{inicio: string, fim: string, d1: bool}
     * }
     */
    public function ranking(
        ?array $codVendedores,
        int $ano,
        int $mes,
        string $modo = 'mensal',
        string $busca = '',
        string $faixa = '',
    ): array {
        $mesInicio = $modo === 'acumulado' ? 1 : $mes;
        $mesFim = $mes;
        [$inicio, $fim] = $this->intervaloDatas($ano, $mesInicio, $mesFim);
        $d1 = $this->usaD1($ano, $mesFim);

        $usuarios = $this->usuariosDoEscopo($codVendedores);
        $codigos = $usuarios
            ->pluck('vendedorPerfil.cod_vendedor')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $metasFat = $this->metasPorCodigo($codigos, $ano, $mesInicio, $mesFim, 'faturamento');
        $metasVenda = $this->metasPorCodigo($codigos, $ano, $mesInicio, $mesFim, 'venda');
        $fatRealizado = $this->faturamentoPorCodigo($codigos, $inicio, $fim);
        $vendaRealizado = $this->vendaPorCodigo($codigos, $inicio, $fim);

        $linhas = $usuarios->map(function (User $u) use ($metasFat, $metasVenda, $fatRealizado, $vendaRealizado) {
            $cod = $u->vendedorPerfil->cod_vendedor;
            $fatMeta = (float) ($metasFat[$cod] ?? 0);
            $vendaMeta = (float) ($metasVenda[$cod] ?? 0);
            $fatReal = (float) ($fatRealizado[$cod] ?? 0);
            $vendaReal = (float) ($vendaRealizado[$cod] ?? 0);

            return [
                'userId' => $u->id,
                'nome' => $u->display_name ?: $u->name,
                'perfil' => $u->getRoleNames()->first(),
                'codVendedor' => $cod,
                'codSuper' => $u->vendedorPerfil->cod_super,
                'fatRealizado' => $fatReal,
                'fatMeta' => $fatMeta,
                'fatPct' => $fatMeta > 0 ? round(($fatReal / $fatMeta) * 100, 1) : null,
                'vendaRealizado' => $vendaReal,
                'vendaMeta' => $vendaMeta,
                'vendaPct' => $vendaMeta > 0 ? round(($vendaReal / $vendaMeta) * 100, 1) : null,
                'semMeta' => $fatMeta <= 0 && $vendaMeta <= 0,
            ];
        });

        if ($busca !== '') {
            $termo = mb_strtolower($busca);
            $linhas = $linhas->filter(function (array $l) use ($termo) {
                return str_contains(mb_strtolower($l['nome']), $termo)
                    || str_contains(mb_strtolower((string) $l['codVendedor']), $termo);
            });
        }

        // KPIs sobre o universo pós-busca / pré-faixa (chips continuam corretos ao filtrar).
        $kpis = [
            'total' => $linhas->count(),
            'atingiu' => $linhas->filter(fn (array $l) => $l['fatPct'] !== null && $l['fatPct'] >= 100)->count(),
            'quase' => $linhas->filter(fn (array $l) => $l['fatPct'] !== null && $l['fatPct'] >= 80 && $l['fatPct'] < 100)->count(),
            'abaixo' => $linhas->filter(fn (array $l) => $l['fatPct'] !== null && $l['fatPct'] < 80)->count(),
            'semMeta' => $linhas->filter(fn (array $l) => $l['semMeta'])->count(),
        ];

        if ($faixa !== '') {
            $linhas = $linhas->filter(fn (array $l) => $this->bateFaixa($l, $faixa));
        }

        // Ordena por % fat (sem meta no fim); desempate por nome.
        $linhas = $linhas
            ->sort(function (array $a, array $b) {
                $pa = $a['fatPct'];
                $pb = $b['fatPct'];
                if ($pa === null && $pb === null) {
                    return strcmp($a['nome'], $b['nome']);
                }
                if ($pa === null) {
                    return 1;
                }
                if ($pb === null) {
                    return -1;
                }
                if ($pa === $pb) {
                    return strcmp($a['nome'], $b['nome']);
                }

                return $pb <=> $pa;
            })
            ->values();

        $totaisFonte = $linhas->unique('codVendedor');
        $totais = [
            'fatRealizado' => round((float) $totaisFonte->sum('fatRealizado'), 2),
            'fatMeta' => round((float) $totaisFonte->sum('fatMeta'), 2),
            'vendaRealizado' => round((float) $totaisFonte->sum('vendaRealizado'), 2),
            'vendaMeta' => round((float) $totaisFonte->sum('vendaMeta'), 2),
        ];
        $totais['fatPct'] = $totais['fatMeta'] > 0
            ? round(($totais['fatRealizado'] / $totais['fatMeta']) * 100, 1)
            : null;
        $totais['vendaPct'] = $totais['vendaMeta'] > 0
            ? round(($totais['vendaRealizado'] / $totais['vendaMeta']) * 100, 1)
            : null;

        return [
            'linhas' => $linhas->all(),
            'totais' => $totais,
            'kpis' => $kpis,
            'periodo' => [
                'inicio' => $inicio,
                'fim' => $fim,
                'd1' => $d1,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} [inicio, fim] Y-m-d
     */
    public function intervaloDatas(int $ano, int $mesInicio, int $mesFim): array
    {
        $inicio = Carbon::create($ano, $mesInicio, 1)->toDateString();
        $fim = $this->fimRealizado($ano, $mesFim)->toDateString();

        return [$inicio, $fim];
    }

    public function fimRealizado(int $ano, int $mes): Carbon
    {
        $hoje = now()->startOfDay();
        $fimMes = Carbon::create($ano, $mes, 1)->endOfMonth()->startOfDay();

        // Mês passado: mês cheio.
        if ($ano < $hoje->year || ($ano === $hoje->year && $mes < $hoje->month)) {
            return $fimMes;
        }

        // Mês futuro: intervalo vazio (fim < início).
        if ($ano > $hoje->year || ($ano === $hoje->year && $mes > $hoje->month)) {
            return Carbon::create($ano, $mes, 1)->subDay()->startOfDay();
        }

        // Mês corrente: D-1; segunda → D-3 (sexta).
        $fim = $hoje->copy()->subDay();
        if ($hoje->isMonday()) {
            $fim = $hoje->copy()->subDays(3);
        }

        $inicioMes = Carbon::create($ano, $mes, 1)->startOfDay();
        if ($fim->lt($inicioMes)) {
            return $inicioMes->copy()->subDay();
        }

        return $fim;
    }

    private function usaD1(int $ano, int $mes): bool
    {
        $hoje = now();

        return $ano === (int) $hoje->year && $mes === (int) $hoje->month;
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return Collection<int, User>
     */
    private function usuariosDoEscopo(?array $codVendedores): Collection
    {
        if ($codVendedores !== null && $codVendedores === []) {
            return collect();
        }

        /*
         * ⚠️ SUPERVISOR ENTRA NO UNIVERSO DO RANKING. Na Autopel o supervisor também
         * vende, e havia R$ 9,04 mi de meta gravados em códigos de supervisor que este
         * filtro descartava — a meta existia na tabela e nunca aparecia em lugar nenhum,
         * nem na linha, nem no total.
         *
         * ⚠️ EFEITO COLATERAL VISÍVEL, e é a correção, não um acidente: o gauge de meta da
         * EMPRESA INTEIRA (metaVsFaturamento com escopo null) deriva a lista daqui, então
         * o número do admin no Painel MUDA — passa a somar as metas de supervisor que
         * antes ficavam de fora.
         */
        $query = User::role(['vendedor', 'representante', 'supervisor'])
            ->where('is_active', true)
            ->whereHas('vendedorPerfil', function ($q) use ($codVendedores) {
                $q->whereNotNull('cod_vendedor')->where('cod_vendedor', '!=', '');
                if ($codVendedores !== null) {
                    $q->whereIn('cod_vendedor', $codVendedores);
                }
            })
            ->with(['vendedorPerfil', 'roles']);

        return $query->get()->sortBy(fn (User $u) => mb_strtolower($u->display_name ?: $u->name))->values();
    }

    /**
     * @param  list<string>  $codigos
     * @return array<string, float>
     */
    private function metasPorCodigo(array $codigos, int $ano, int $mesInicio, int $mesFim, string $tipo): array
    {
        if ($codigos === []) {
            return [];
        }

        return MetaMensal::query()
            ->selectRaw('cod_vendedor, SUM(valor_meta) as total')
            ->where('ano', $ano)
            ->whereBetween('mes', [$mesInicio, $mesFim])
            ->where('tipo', $tipo)
            ->whereIn('cod_vendedor', $codigos)
            ->groupBy('cod_vendedor')
            ->pluck('total', 'cod_vendedor')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @param  list<string>  $codigos
     * @return array<string, float>
     */
    private function faturamentoPorCodigo(array $codigos, string $inicio, string $fim): array
    {
        if ($codigos === [] || $fim < $inicio) {
            return [];
        }

        return Faturamento::query()
            ->selectRaw('cod_vendedor, SUM(valor_total) as total')
            ->whereBetween('data_emissao', [$inicio, $fim])
            ->whereIn('cod_vendedor', $codigos)
            ->groupBy('cod_vendedor')
            ->pluck('total', 'cod_vendedor')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Pedidos emitidos (toda a tabela) — mesma convenção da Home (data_pedido).
     *
     * @param  list<string>  $codigos
     * @return array<string, float>
     */
    private function vendaPorCodigo(array $codigos, string $inicio, string $fim): array
    {
        if ($codigos === [] || $fim < $inicio) {
            return [];
        }

        return Pedido::query()
            ->selectRaw('cod_vendedor, SUM(valor_total) as total')
            ->whereBetween('data_pedido', [$inicio, $fim])
            ->whereIn('cod_vendedor', $codigos)
            ->groupBy('cod_vendedor')
            ->pluck('total', 'cod_vendedor')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** @param array<string, mixed> $linha */
    private function bateFaixa(array $linha, string $faixa): bool
    {
        return match ($faixa) {
            'atingiu' => $linha['fatPct'] !== null && $linha['fatPct'] >= 100,
            'quase' => $linha['fatPct'] !== null && $linha['fatPct'] >= 80 && $linha['fatPct'] < 100,
            'abaixo' => $linha['fatPct'] !== null && $linha['fatPct'] < 80,
            'sem_meta' => $linha['semMeta'],
            default => true,
        };
    }
}
