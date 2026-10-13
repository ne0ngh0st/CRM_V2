<?php

namespace App\Services\VisaoDiretor;

use App\Models\Lead;
use App\Models\Segmento;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Receita\PorteEmpresa;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A tabela de cada segmento da Visão Diretor → Maiores por Segmento (decisão do Tony,
 * 2026-10-13): as REDES primeiro, na ordem do ranking (cada uma expande para os clientes
 * dela), depois TODOS os outros clientes e leads do segmento, do maior capital ao menor.
 * Uma tabela, uma paginação — rede, cliente e lead são "a mesma coisa".
 *
 * Grão:
 * - **cliente** = `cod_cliente` com ao menos uma filial no segmento (o mesmo conjunto que
 *   `/carteira?segmento=X` agrupada lista). Lojas e capital contam só as filiais do
 *   segmento; a última compra é a de TODAS as filiais, a mesma que dá a pill da Carteira.
 * - **lead** = lead visível do segmento (por nome), fora da etapa "Outros", cujo CNPJ NÃO
 *   é de cliente (CNPJ exato, a regra do import de leads).
 *
 * Lê a tabela `visao_diretor_universo`, que só `reconstruir()` escreve. O status é
 * calculado na consulta a partir da data, então não envelhece entre reconstruções; quem
 * envelhece é quem entra e sai — por isso a tela mostra quando foi reconstruída.
 */
class UniversoDoSegmento
{
    public const POR_PAGINA = 30;

    public function __construct(
        private readonly ClienteStatusResolver $statusResolver,
        private readonly ClientesDaConta $clientesDaConta,
        private readonly MaioresPorSegmentoResolver $redesResolver,
        private readonly BuscaNasRedes $buscaNasRedes,
        private readonly VinculosSuspeitos $vinculosSuspeitos,
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {
    }

    /**
     * Refaz a tabela inteira. Tudo numa transação: quem lê no meio continua vendo a versão
     * anterior (REPEATABLE READ), nunca uma lista pela metade.
     *
     * @return array{clientes: int, leads: int}
     */
    public function reconstruir(): array
    {
        $agora = Carbon::now();

        return DB::transaction(function () use ($agora) {
            DB::table('visao_diretor_universo')->delete();

            $clientes = DB::insert(<<<'SQL'
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, cod_cliente, cliente_id, nome, lojas,
                     data_ultima_compra, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT c.cod_segmento, 'cliente', c.cod_cliente, MIN(c.id), '', COUNT(*),
                       u.uc, MAX(cs.capital_social), COALESCE(MAX(cs.capital_social), -1),
                       ELT(MAX(FIELD(cs.porte, 'ME', 'EPP', 'DEMAIS')), 'ME', 'EPP', 'DEMAIS'),
                       ?
                FROM clientes c
                JOIN segmentos s ON s.codigo = c.cod_segmento
                JOIN (SELECT cod_cliente, MAX(data_ultima_compra) AS uc FROM clientes GROUP BY cod_cliente) u
                    ON u.cod_cliente = c.cod_cliente
                LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
                GROUP BY c.cod_segmento, c.cod_cliente, u.uc
                SQL, [$agora]);

            /*
             * O porte é o MAIOR entre as filiais do segmento (ELT/FIELD), o par do capital
             * máximo. GROUP_CONCAT ordenado estourava o limite de 1.024 bytes em cliente com
             * centenas de filiais.
             *
             * Nome, documento, UF e vendedor saem da filial-âncora. O nome exibido é a
             * RAZÃO SOCIAL, como na Carteira agrupada: a linha é o cliente inteiro (status e
             * última compra de todas as lojas), e o fantasia da âncora é o de UMA loja — ao
             * lado do status do cliente, parecia que aquela loja estava ativa (13/10/2026).
             * O fantasia fica guardado para a busca.
             */
            DB::update(<<<'SQL'
                UPDATE visao_diretor_universo u
                JOIN clientes a ON a.id = u.cliente_id
                SET u.nome = COALESCE(NULLIF(a.razao_social, ''), a.nome_fantasia),
                    u.razao_social = a.razao_social,
                    u.nome_fantasia = NULLIF(a.nome_fantasia, ''),
                    u.documento = a.cnpj,
                    u.uf = NULLIF(a.estado, ''),
                    u.cod_vendedor = NULLIF(a.cod_vendedor, '')
                WHERE u.tipo = 'cliente'
                SQL);

            /*
             * ⚠️ O CNPJ do lead pode vir com máscara e sem o zero que o Excel come; os
             * dígitos são normalizados aqui como `Normalizador::digitosCnpj()` faz (12-13
             * dígitos → 14). Lead sem CNPJ (o aberto pela diretoria) entra: não há como
             * dizer que já é cliente.
             */
            DB::insert(<<<'SQL'
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, lead_id, nome, razao_social, documento, uf, cod_vendedor,
                     lojas, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT s.codigo, 'lead', l.id, l.nome, l.razao_social, l.cnpj, NULLIF(l.estado, ''),
                       NULLIF(l.cod_vendedor, ''), 1, cs.capital_social, COALESCE(cs.capital_social, -1), cs.porte, ?
                FROM (
                    SELECT id, nome, razao_social, cnpj, estado, cod_vendedor, segmento,
                           CASE WHEN CHAR_LENGTH(REGEXP_REPLACE(cnpj, '[^0-9]', '')) IN (12, 13)
                                THEN LPAD(REGEXP_REPLACE(cnpj, '[^0-9]', ''), 14, '0')
                                ELSE NULLIF(REGEXP_REPLACE(cnpj, '[^0-9]', ''), '') END AS digitos
                    FROM leads
                    WHERE status <> 'excluido' AND etapa <> 'outros' AND segmento IS NOT NULL
                ) l
                JOIN segmentos s ON s.nome = l.segmento
                LEFT JOIN cnpj_situacoes cs ON cs.cnpj = l.digitos
                WHERE l.digitos IS NULL
                   OR NOT EXISTS (SELECT 1 FROM clientes c WHERE c.cnpj_digitos = l.digitos)
                SQL, [$agora]);

            return [
                'clientes' => DB::table('visao_diretor_universo')->where('tipo', 'cliente')->count(),
                'leads' => DB::table('visao_diretor_universo')->where('tipo', 'lead')->count(),
            ];
        });
    }

    /** Quando a tabela foi reconstruída pela última vez (null = nunca). */
    public function reconstruidoEm(): ?Carbon
    {
        $valor = DB::table('visao_diretor_universo')->max('reconstruido_em');

        return $valor ? Carbon::parse($valor) : null;
    }

    /**
     * Total de clientes + leads por segmento, para as abas. Uma varredura da tabela.
     *
     * @return array<string, int> por código do segmento
     */
    public function totaisPorSegmento(): array
    {
        return DB::table('visao_diretor_universo')
            ->select('segmento_codigo')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('segmento_codigo')
            ->pluck('total', 'segmento_codigo')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Uma página da tabela do segmento: as REDES dele primeiro (ordem do ranking/planilha),
     * depois os clientes e leads que não pertencem a nenhuma delas, por capital social.
     * Uma lista só, um paginador só (decisão do Tony, 2026-10-13: "é tudo a mesma coisa").
     *
     * Cada linha tem `tipo`: `rede` (a linha do `MaioresPorSegmentoResolver`, que expande
     * para os clientes dela), `cliente` ou `lead`.
     *
     * @param  array{status?: string, busca?: string, uf?: string, pagina?: int}  $filtros
     */
    public function lista(string $segmento, array $filtros = []): array
    {
        $status = $this->status($filtros['status'] ?? '');
        $pagina = max(1, (int) ($filtros['pagina'] ?? 1));
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $uf = (string) ($filtros['uf'] ?? '');

        $todas = $this->redesDoSegmento($segmento);
        $redes = $this->filtrarBuscaEUf($todas, $busca, $uf);
        $base = $this->base($segmento, $busca, $uf, $todas->pluck('id')->all());

        /*
         * As contagens IGNORAM o filtro de status (os tiles são o próprio filtro: um filtro
         * não se aplica à faceta que controla — mesma regra do card da Carteira). Busca e
         * UF continuam valendo. Contam LINHAS da tabela: uma rede vale uma.
         */
        $contagens = $this->contar(clone $base, $redes);

        $redesFiltradas = $this->filtrarRedes($redes, $status);
        $filtrada = clone $base;
        $this->aplicarStatus($filtrada, $status);
        /*
         * Quantos clientes/leads passam no status, sem outra consulta: cada contagem de
         * status já é avulsos + redes daquele status, e as redes filtradas são exatamente as
         * redes desses status.
         */
        $avulsos = $status === ''
            ? $contagens['clientes'] + $contagens['leads']
            : array_sum(array_map(fn ($s) => $contagens[$s], MaioresPorSegmentoResolver::FILTROS_STATUS[$status]))
                - $redesFiltradas->count();

        // Paginação sobre a concatenação: as redes ocupam as primeiras posições.
        $offset = ($pagina - 1) * self::POR_PAGINA;
        $daPagina = $redesFiltradas->slice($offset, self::POR_PAGINA)->values();
        $vagas = self::POR_PAGINA - $daPagina->count();

        // Vínculos com cara de errado, só das redes desta página (`VinculosSuspeitos`).
        $suspeitos = $this->vinculosSuspeitos->porConta($daPagina);

        $linhas = $vagas > 0
            ? $this->ordenar($filtrada)->offset(max(0, $offset - $redesFiltradas->count()))->limit($vagas)->get()
            : collect();

        return [
            'segmento' => $segmento,
            // Paginador do Laravel, no formato que `Pagination.vue` já consome. Quem chama
            // decide o caminho dos links (`withPath`); o parâmetro da página é `pagina`.
            'linhas' => new LengthAwarePaginator(
                [...$daPagina->map(fn (array $r) => $this->linhaDeRede($r, $suspeitos[$r['id']] ?? []))->all(), ...$this->montarLinhas($linhas, $segmento)],
                $redesFiltradas->count() + $avulsos,
                self::POR_PAGINA,
                $pagina,
                ['pageName' => 'pagina'],
            ),
            'contagens' => $contagens,
            /*
             * Rodapé da tabela: as redes EXIBIDAS (com todos os filtros), em todas as
             * páginas — não só as da página atual, senão o subtotal mudaria ao paginar.
             * Soma como `MaioresPorSegmentoResolver::resumir()`: sem filtro bate com o
             * "N filiais no CRM" do cabeçalho.
             */
            'subtotalRedes' => [
                'redes' => $redesFiltradas->count(),
                'filiais' => (int) $redesFiltradas->sum('lojas'),
                'clientes' => (int) $redesFiltradas->sum('clientes'),
            ],
            'filtros' => ['status' => $status, 'busca' => $busca, 'uf' => $uf],
            'reconstruidoEm' => $this->reconstruidoEm()?->toIso8601String(),
        ];
    }

    /**
     * A tabela inteira, sem paginar, na ordem da tela — para o Excel. Mesmos filtros de
     * `lista()`: redes primeiro, depois as linhas de `montarLinhas()` em lotes de 1.000.
     *
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function tudo(string $segmento, array $filtros = []): array
    {
        $status = $this->status($filtros['status'] ?? '');
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $uf = (string) ($filtros['uf'] ?? '');

        $todas = $this->redesDoSegmento($segmento);
        $redes = $this->filtrarBuscaEUf($todas, $busca, $uf);
        $query = $this->base($segmento, $busca, $uf, $todas->pluck('id')->all());
        $this->aplicarStatus($query, $status);

        $linhas = $this->filtrarRedes($redes, $status)->map(fn (array $r) => $this->linhaDeRede($r))->all();

        $this->ordenar($query)->chunk(1000, function (Collection $lote) use (&$linhas, $segmento) {
            array_push($linhas, ...$this->montarLinhas($lote, $segmento));
        });

        return $linhas;
    }

    /** Valor do filtro de status, pela whitelist da página (inclui "A trabalhar + lead"). */
    private function status(string $valor): string
    {
        return array_key_exists($valor, MaioresPorSegmentoResolver::FILTROS_STATUS) ? $valor : '';
    }

    /**
     * As redes (contas estratégicas) do segmento, na ordem do ranking. Vêm do
     * `MaioresPorSegmentoResolver` — os mesmos números do Resumo. Uma vez por request.
     *
     * @return Collection<int, array>
     */
    private function redesDoSegmento(string $segmento): Collection
    {
        return $this->redesResolver->linhas($segmento)->values();
    }

    /** Busca (no nome) e UF sobre as redes — a mesma busca da página antes desta lista. */
    private function filtrarBuscaEUf(Collection $redes, string $busca, string $uf): Collection
    {
        // A busca olha nome, apelidos e quem está DENTRO da rede (`BuscaNasRedes`).
        return $this->buscaNasRedes->filtrar(
            $redes->when($uf !== '', fn ($c) => $c->where('uf', $uf))->values(),
            $busca,
        );
    }

    /** @param  Collection<int, array>  $redes */
    private function filtrarRedes(Collection $redes, string $status): Collection
    {
        if ($status === '') {
            return $redes;
        }

        return $redes->whereIn('status', MaioresPorSegmentoResolver::FILTROS_STATUS[$status])->values();
    }

    /**
     * A linha de rede como a tabela recebe: a do resolver, marcada.
     *
     * @param  list<array{tipo: string, codigo: string, motivo: string, texto: string}>  $suspeitos
     */
    private function linhaDeRede(array $rede, array $suspeitos = []): array
    {
        return [
            ...collect($rede)->except('segmento')->all(),
            'tipo' => 'rede',
            'chave' => "rede-{$rede['id']}",
            'suspeitos' => $suspeitos,
        ];
    }

    /** Do maior capital ao menor; `id` desempata no mesmo sentido (o índice atende os dois). */
    private function ordenar(Builder $query): Builder
    {
        return $query->orderByDesc('capital_ordem')->orderByDesc('id');
    }

    /**
     * Os clientes e leads do segmento que NÃO pertencem a nenhuma rede dele, com busca e UF.
     *
     * Quem pertence à rede está dentro da linha dela (expande), então não se repete solto:
     * - cliente: algum vínculo da rede casa com uma filial dele (`ClientesDaConta`);
     * - lead: ligado à rede com confirmação (`Lead::ligadoAConta`).
     * Os dois lidos ao vivo: editar os vínculos de uma conta vale no request seguinte.
     *
     * ⚠️ Só redes DESTE segmento. Cliente de 115 ligado a uma rede de 108 (Magazine Luiza)
     * continua solto em 115: lá não há linha de rede que o mostre.
     *
     * @param  list<int>  $contas  TODAS as redes do segmento (sem busca/UF/status: quem é da
     *                         rede não vira avulso só porque a rede saiu do filtro)
     */
    private function base(string $segmento, string $busca, string $uf, array $contas): Builder
    {
        $query = DB::table('visao_diretor_universo')->where('segmento_codigo', $segmento);

        if ($contas !== []) {
            $codigos = DB::query()
                ->fromSub($this->clientesDaConta->paresDeTodasAsContas($contas), 'p')
                ->whereIn('p.conta_id', $contas)
                ->distinct()
                ->pluck('p.cod_cliente')
                ->all();
            $leads = Lead::query()->ligadoAConta()->whereIn('conta_estrategica_id', $contas)->pluck('id')->all();

            if ($codigos !== []) {
                $query->where(fn (Builder $q) => $q->where('tipo', 'lead')->orWhereNotIn('cod_cliente', $codigos));
            }
            if ($leads !== []) {
                $query->where(fn (Builder $q) => $q->where('tipo', 'cliente')->orWhereNotIn('lead_id', $leads));
            }
        }

        if ($uf !== '') {
            $query->where('uf', $uf);
        }

        if ($busca !== '') {
            // Começo de palavra, a mesma regra das redes. Documento é gravado com máscara.
            [$casa, $bindings] = TermoDeBusca::sql(['nome', 'razao_social', 'nome_fantasia'], $busca, "REGEXP_REPLACE(documento, '[^0-9]', '')");
            $query->whereRaw($casa, $bindings);
        }

        return $query;
    }

    /** Mesmo corte da Carteira (`ClienteStatusResolver`), na mesma formulação de datas. */
    private function aplicarStatus(Builder $query, string $status): void
    {
        if ($status === '') {
            return;
        }

        [$ativo, $inativando] = $this->limites();

        $query->where(function (Builder $q) use ($status, $ativo, $inativando) {
            foreach (MaioresPorSegmentoResolver::FILTROS_STATUS[$status] as $s) {
                $q->orWhere(fn (Builder $c) => match ($s) {
                    'lead' => $c->where('tipo', 'lead'),
                    'ativo' => $c->where('tipo', 'cliente')->where('data_ultima_compra', '>=', $ativo),
                    'inativando' => $c->where('tipo', 'cliente')
                        ->where('data_ultima_compra', '<', $ativo)
                        ->where('data_ultima_compra', '>=', $inativando),
                    'inativo' => $c->where('tipo', 'cliente')
                        ->where(fn (Builder $d) => $d->whereNull('data_ultima_compra')->orWhere('data_ultima_compra', '<', $inativando)),
                });
            }
        });
    }

    /**
     * Linhas da tabela por status: redes (pelo status delas) + clientes + leads.
     *
     * @param  Collection<int, array>  $redes
     * @return array{total: int, redes: int, clientes: int, leads: int, ativo: int, inativando: int, inativo: int, lead: int}
     */
    private function contar(Builder $query, Collection $redes): array
    {
        [$ativo, $inativando] = $this->limites();

        $r = $query
            ->selectRaw("SUM(tipo = 'cliente') as clientes")
            ->selectRaw("SUM(tipo = 'lead') as leads")
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra >= ?) as ativo", [$ativo])
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra < ? AND data_ultima_compra >= ?) as inativando", [$ativo, $inativando])
            ->selectRaw("SUM(tipo = 'cliente' AND (data_ultima_compra IS NULL OR data_ultima_compra < ?)) as inativo", [$inativando])
            ->first();

        $porStatus = $redes->countBy('status');

        $c = [
            'redes' => $redes->count(),
            'clientes' => (int) $r->clientes,
            'leads' => (int) $r->leads,
            'ativo' => (int) $r->ativo + $porStatus->get('ativo', 0),
            'inativando' => (int) $r->inativando + $porStatus->get('inativando', 0),
            'inativo' => (int) $r->inativo + $porStatus->get('inativo', 0),
            'lead' => (int) $r->leads + $porStatus->get('lead', 0),
        ];

        return ['total' => $c['redes'] + $c['clientes'] + $c['leads'], ...$c];
    }

    /** @return array{0: string, 1: string} */
    private function limites(): array
    {
        return [
            $this->statusResolver->limiteAtivo()->toDateString(),
            $this->statusResolver->limiteInativando()->toDateString(),
        ];
    }

    /**
     * As linhas da tela e do Excel, com rede e nome do vendedor. Consultas para o lote inteiro
     * (30 na tela, 1.000 por lote no Excel), nunca por linha.
     *
     * @param  Collection<int, object>  $linhas
     */
    public function montarLinhas(Collection $linhas, string $segmento): array
    {
        $nomes = $this->nomeVendedor->porCodigo($linhas->pluck('cod_vendedor')->filter()->unique());
        $hoje = Carbon::now();

        return $linhas->map(function ($l) use ($nomes, $hoje) {
            $ehCliente = $l->tipo === 'cliente';
            $uc = $l->data_ultima_compra ? Carbon::parse($l->data_ultima_compra) : null;

            return [
                'chave' => "{$l->tipo}-".($ehCliente ? $l->cod_cliente : $l->lead_id),
                'tipo' => $l->tipo,
                'clienteId' => $l->cliente_id,
                'leadId' => $l->lead_id,
                'codCliente' => $l->cod_cliente,
                'nome' => $l->nome,
                'razaoSocial' => $l->razao_social,
                // Fantasia só quando diz algo a mais que o nome: é por ele que "OBA" acha "MUSSIMI E CIA".
                'nomeFantasia' => $l->nome_fantasia && mb_strtoupper($l->nome_fantasia) !== mb_strtoupper($l->nome) ? $l->nome_fantasia : null,
                'documento' => $l->documento,
                'uf' => $l->uf,
                'lojas' => (int) $l->lojas,
                // Mesmo formato que `CapitalPorte.vue` recebe na Carteira e nos Leads.
                'receita' => [
                    'capitalSocial' => $l->capital_social !== null ? (float) $l->capital_social : null,
                    'porte' => PorteEmpresa::rotulo($l->porte),
                ],
                'status' => $ehCliente ? $this->statusResolver->statusPara($uc, $hoje) : 'lead',
                'ultimaCompra' => $uc?->toDateString(),
                'vendedor' => $l->cod_vendedor ? ($nomes[$l->cod_vendedor] ?? $l->cod_vendedor) : null,
            ];
        })->values()->all();
    }

    /** Segmentos que existem, para validar o `?segmento=`. */
    public static function segmentoValido(string $codigo): bool
    {
        return $codigo !== '' && Segmento::query()->where('codigo', $codigo)->exists();
    }
}
