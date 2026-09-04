<?php

namespace App\Services\Metas;

use App\Models\Faturamento;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Ranking meta × realizado em batch (sem N+1).
 *
 * Realizado faturamento = SUM(faturamentos.valor_total) por data_emissao.
 * Realizado venda = SUM(pedidos.valor_total) por data_pedido (mesma base da Home "pedidos emitidos").
 * O de-para tipo → tabela/coluna vive num lugar só: {@see self::queryRealizado()}.
 * Mês corrente: realizado até D-1 (segunda → D-3), alinhado ao legado gestao_metas.
 */
class MetaRankingResolver
{
    /**
     * Tipos de meta que o sistema conhece. Espelha o enum de `metas_mensais.tipo`.
     *
     * ⚠️ É a whitelist usada por {@see self::metaVsRealizado()}: o tipo chega do front
     * (aba do card) e escolhe TABELA e COLUNA DE DATA. Valor fora daqui tem que estourar,
     * nunca cair num default silencioso — um tipo desconhecido lido como "faturamento"
     * mostraria o número errado sob o rótulo certo.
     *
     * @var list<string>
     */
    public const TIPOS = ['faturamento', 'venda'];

    /**
     * Agregado único (Home / gauges): meta vs realizado no intervalo de meses.
     *
     * `venda` = pedido emitido (`pedidos.data_pedido`); `faturamento` = nota emitida
     * (`faturamentos.data_emissao`). Os dois são R$ e vivem na mesma tabela de metas,
     * separados por `tipo` — o que muda é a fonte do realizado.
     *
     * Universo alinhado ao ranking: só códigos de vendedor/representante/supervisor
     * ativos. Sem isso o % da Home divergiria de /metas para o mesmo período.
     *
     * @param  array<string>|null  $codVendedores  null = empresa (vendedores ativos); [] = vazio
     * @return array{meta: float, realizado: float, percentual: float, tipo: string}
     */
    public function metaVsRealizado(
        ?array $codVendedores,
        int $ano,
        int $mesInicio,
        int $mesFim,
        string $tipo = 'faturamento',
    ): array {
        $this->garantirTipo($tipo);

        $codVendedores = $this->codigosDoEscopo($codVendedores);

        if ($codVendedores === []) {
            return ['meta' => 0.0, 'realizado' => 0.0, 'percentual' => 0.0, 'tipo' => $tipo];
        }

        [$inicio, $fim] = $this->intervaloDatas($ano, $mesInicio, $mesFim);

        $meta = (float) MetaMensal::query()
            ->where('ano', $ano)
            ->whereBetween('mes', [$mesInicio, $mesFim])
            ->where('tipo', $tipo)
            ->whereIn('cod_vendedor', $codVendedores)
            ->sum('valor_meta');

        $realizado = 0.0;
        if ($fim >= $inicio) {
            $realizado = (float) $this->queryRealizado($tipo)
                ->whereBetween($this->colunaDataDoTipo($tipo), [$inicio, $fim])
                ->whereIn('cod_vendedor', $codVendedores)
                ->sum('valor_total');
        }

        return [
            'meta' => $meta,
            'realizado' => $realizado,
            'percentual' => $meta > 0 ? round(($realizado / $meta) * 100, 1) : 0.0,
            'tipo' => $tipo,
        ];
    }

    /**
     * Resolve `null` (empresa inteira) na lista de códigos ativos do ranking; devolve o
     * que veio, intacto, para qualquer escopo já explícito.
     *
     * ⚠️ É público porque quem monta um bloco com VÁRIAS agregações no mesmo escopo deve
     * resolver UMA vez e passar o resultado adiante. Cada resolução custa 6 queries (roles
     * do spatie + perfis), e o gauge do Painel faz quatro agregações — resolver dentro de
     * cada uma multiplicava esse custo por quatro, sem mudar o resultado.
     *
     * @param  array<string>|null  $codVendedores
     * @return list<string>
     */
    public function codigosDoEscopo(?array $codVendedores): array
    {
        if ($codVendedores !== null) {
            return array_values($codVendedores);
        }

        return $this->usuariosDoEscopo(null)
            ->pluck('vendedorPerfil.cod_vendedor')
            ->filter()
            ->unique()
            ->values()
            ->all();
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
        $fatRealizado = $this->realizadoPorCodigo('faturamento', $codigos, $inicio, $fim);
        $vendaRealizado = $this->realizadoPorCodigo('venda', $codigos, $inicio, $fim);

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
         * EMPRESA INTEIRA (metaVsRealizado com escopo null) deriva a lista daqui, então
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
     * Realizado por código, no tipo pedido.
     *
     * ⚠️ Substituiu `faturamentoPorCodigo()` + `vendaPorCodigo()`, que eram o MESMO
     * código com outra tabela e outra coluna de data. Com as duas cópias, "qual é a fonte
     * do realizado de venda" tinha duas respostas possíveis — e o gauge do Painel virou
     * uma terceira. Agora a resposta mora só em {@see self::queryRealizado()}.
     *
     * @param  list<string>  $codigos
     * @return array<string, float>
     */
    private function realizadoPorCodigo(string $tipo, array $codigos, string $inicio, string $fim): array
    {
        if ($codigos === [] || $fim < $inicio) {
            return [];
        }

        return $this->queryRealizado($tipo)
            ->selectRaw('cod_vendedor, SUM(valor_total) as total')
            ->whereBetween($this->colunaDataDoTipo($tipo), [$inicio, $fim])
            ->whereIn('cod_vendedor', $codigos)
            ->groupBy('cod_vendedor')
            ->pluck('total', 'cod_vendedor')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * A ÚNICA definição de "de onde vem o realizado de cada tipo de meta".
     *
     * venda       → `pedidos` (todo pedido emitido, aberto ou faturado)
     * faturamento → `faturamentos` (nota emitida)
     *
     * Venda NÃO é subconjunto do faturamento: é o que foi vendido, tenha virado nota ou
     * não. Por isso as duas séries podem se cruzar no gráfico e o realizado de venda pode
     * superar o de faturamento no mesmo mês.
     */
    private function queryRealizado(string $tipo): Builder
    {
        $this->garantirTipo($tipo);

        return $tipo === 'venda' ? Pedido::query() : Faturamento::query();
    }

    private function colunaDataDoTipo(string $tipo): string
    {
        return $tipo === 'venda' ? 'data_pedido' : 'data_emissao';
    }

    /**
     * ⚠️ Explode em vez de cair num default. O tipo vem da aba escolhida no front e
     * decide QUAL TABELA responde pelo número; um valor desconhecido tratado como
     * "faturamento" exibiria o realizado errado sob o rótulo certo, sem erro nenhum.
     */
    private function garantirTipo(string $tipo): void
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de meta desconhecido: {$tipo}.");
        }
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
