<?php

namespace App\Services\VisaoDiretor;

use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A tabela de cada segmento da Visão Diretor → Maiores por Segmento: onde a Autopel atua
 * (cliente ativo), deixou de atuar (perdendo / a trabalhar) e nunca atuou (lead).
 *
 * Redesenhada em 2026-10-14 (Tony): a página é para o dono da empresa, e redes, contas e
 * vínculos confundiam mais do que explicavam. Agora é o mesmo grão da Carteira:
 * - **cliente** = `cod_cliente` com ao menos uma filial no segmento (o mesmo conjunto que
 *   `/carteira?segmento=X` agrupada lista). Filiais contam só as do segmento; o status vem
 *   da última compra de TODAS as filiais, a mesma da pill da Carteira.
 * - **lead** = lead visível do segmento (por nome), fora da etapa "Outros", cujo CNPJ NÃO
 *   é de cliente (CNPJ exato, a regra do import de leads).
 * A ordem é o capital social da Receita, que não aparece na tela.
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
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {
    }

    /**
     * A empresa de cada filial, calculada na consulta: a raiz do CNPJ (8 dígitos) ou, sem
     * CNPJ de 14 dígitos (CPF, vazio), o próprio código do cliente.
     *
     * ⚠️ Regra de ouro nº 3: a raiz NUNCA vira coluna armazenada — é esta expressão, usada
     * só dentro de `reconstruir()`. Juntar por empresa foi pedido do Tony (2026-10-14:
     * "pesquisar Carrefour traz duas páginas de Carrefour").
     */
    /** Nome fantasia que é marcador interno do cadastro do TOTVS, não marca (regex, sem caixa). */
    private const FANTASIA_DE_CADASTRO = '^(ENTREGA|ALMOX|RECEBIMENTO|COBRAN|O MESMO|MATRIZ|FILIAL|DEP[OÓ]SITO|CD( |$|-)|LOJA [0-9])|BAIXAD|INAPT|INATIV';

    private const EMPRESA ="CASE WHEN CHAR_LENGTH(c.cnpj_digitos) = 14 THEN LEFT(c.cnpj_digitos, 8) ELSE CONCAT('#', c.cod_cliente) END";

    /**
     * Refaz a tabela inteira. Tudo numa transação: quem lê no meio continua vendo a versão
     * anterior (REPEATABLE READ), nunca uma lista pela metade.
     *
     * @return array{clientes: int, leads: int}
     */
    public function reconstruir(): array
    {
        $agora = Carbon::now();
        $empresa = self::EMPRESA;
        $empresaU = str_replace('c.', 'x.', $empresa);
        $empresaA = str_replace('c.', 'a.', $empresa);

        return DB::transaction(function () use ($agora, $empresa, $empresaU, $empresaA) {
            DB::table('visao_diretor_universo')->delete();

            /*
             * Uma linha por EMPRESA no segmento. Filiais e capital contam só as filiais do
             * segmento; a última compra é a de TODAS as filiais da empresa (qualquer
             * segmento), como a pill da Carteira faz com o cliente.
             *
             * A âncora (`cliente_id`) é a matriz (/0001) quando existe — é dela o nome, o
             * CNPJ, a UF e o vendedor da linha. O nome fantasia vem no passo seguinte.
             * ⚠️ GROUP_CONCAT corta em 1.024 bytes por padrão e, num INSERT, o corte vira
             * ERRO (empresa com centenas de filiais). Daí o limite da sessão.
             */
            DB::statement('SET SESSION group_concat_max_len = 4194304');
            DB::insert(<<<SQL
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, cod_cliente, cliente_id, nome, nome_fantasia, lojas,
                     data_ultima_compra, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT c.cod_segmento, 'cliente', MIN(c.cod_cliente),
                       SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY SUBSTRING(c.cnpj_digitos, 9, 4) = '0001' DESC, c.id), ',', 1),
                       '', NULL,
                       COUNT(*),
                       u.uc, MAX(cs.capital_social), COALESCE(MAX(cs.capital_social), -1),
                       ELT(MAX(FIELD(cs.porte, 'ME', 'EPP', 'DEMAIS')), 'ME', 'EPP', 'DEMAIS'),
                       ?
                FROM clientes c
                JOIN segmentos s ON s.codigo = c.cod_segmento
                JOIN (SELECT {$empresaU} AS empresa, MAX(x.data_ultima_compra) AS uc FROM clientes x GROUP BY empresa) u
                    ON u.empresa = {$empresa}
                LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
                GROUP BY c.cod_segmento, {$empresa}, u.uc
                SQL, [$agora]);

            /*
             * O nome principal é a RAZÃO SOCIAL da âncora (a tela põe o fantasia em cima
             * quando houver). `cod_cliente` passa a ser o da âncora: é o que abre a ficha.
             */
            DB::update(<<<'SQL'
                UPDATE visao_diretor_universo u
                JOIN clientes a ON a.id = u.cliente_id
                SET u.nome = COALESCE(NULLIF(a.razao_social, ''), a.nome_fantasia, a.cod_cliente),
                    u.razao_social = a.razao_social,
                    u.cod_cliente = a.cod_cliente,
                    u.documento = a.cnpj,
                    u.uf = NULLIF(a.estado, ''),
                    u.cod_vendedor = NULLIF(a.cod_vendedor, '')
                WHERE u.tipo = 'cliente'
                SQL);

            /*
             * O nome fantasia da linha é a MARCA: o trecho antes do primeiro "-" ou "("
             * do fantasia de cada filial ("ATACADAO - LJ 1" → "ATACADAO"), o mais frequente
             * entre as filiais da empresa no segmento (empate: o mais curto).
             * Marcadores de uso interno do cadastro (ENTREGA, ALMOX, MATRIZ, CNPJ BAIXADO,
             * LOJA 01…) não contam: escolher o "mais curto" puro trazia justamente eles.
             * Se nenhum fantasia se repete (cada loja do Atacadão tem o seu, e o "vencedor"
             * era um endereço), não há marca: fica sem fantasia e a razão social sobe.
             *
             * ⚠️ Passa por uma tabela TEMPORÁRIA com chave: o JOIN direto contra a derivada
             * (casando pela expressão da empresa) virava laço aninhado sem índice e não
             * terminava em 5 minutos no dev.
             */
            DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_fantasia');
            DB::statement('CREATE TEMPORARY TABLE vdu_fantasia (seg VARCHAR(10), empresa VARCHAR(30), f VARCHAR(255), PRIMARY KEY (seg, empresa)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            DB::insert(<<<SQL
                INSERT INTO vdu_fantasia (seg, empresa, f)
                SELECT seg, empresa, f FROM (
                    SELECT seg, empresa, MIN(marca) AS f, COUNT(*) AS vezes,
                           SUM(COUNT(*)) OVER (PARTITION BY seg, empresa) AS filiais,
                           ROW_NUMBER() OVER (PARTITION BY seg, empresa ORDER BY COUNT(*) DESC, CHAR_LENGTH(MIN(marca))) AS rn
                    FROM (
                        SELECT c.cod_segmento AS seg, {$empresa} AS empresa,
                               TRIM(REGEXP_SUBSTR(TRIM(c.nome_fantasia), '^[^-(]+')) AS marca
                        FROM clientes c
                        WHERE c.cod_segmento IS NOT NULL
                    ) b
                    WHERE marca <> '' AND NOT REGEXP_LIKE(marca, ?, 'i')
                    GROUP BY seg, empresa, UPPER(marca)
                ) t WHERE rn = 1 AND (vezes >= 2 OR filiais = 1)
                SQL, [self::FANTASIA_DE_CADASTRO]);
            DB::update(<<<SQL
                UPDATE visao_diretor_universo u
                JOIN clientes a ON a.id = u.cliente_id
                JOIN vdu_fantasia m ON m.seg = u.segmento_codigo AND m.empresa = {$empresaA}
                SET u.nome_fantasia = m.f
                WHERE u.tipo = 'cliente'
                SQL);
            DB::statement('DROP TEMPORARY TABLE vdu_fantasia');

            /*
             * ⚠️ O CNPJ do lead pode vir com máscara e sem o zero que o Excel come; os
             * dígitos são normalizados aqui como `Normalizador::digitosCnpj()` faz (12-13
             * dígitos → 14). Lead sem CNPJ (o aberto pela diretoria) entra: não há como
             * dizer que já é cliente.
             *
             * Lead de EMPRESA que já é cliente (mesma raiz) não entra: a empresa já está na
             * linha do cliente. O `LIKE 'raiz%'` usa o índice de `cnpj_digitos`.
             */
            DB::insert(<<<'SQL'
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, lead_id, nome, razao_social, nome_fantasia, documento, uf, cod_vendedor,
                     lojas, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT s.codigo, 'lead', l.id, COALESCE(NULLIF(l.razao_social, ''), l.nome), l.razao_social,
                       NULLIF(l.nome, ''), l.cnpj, NULLIF(l.estado, ''),
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
                   OR (CHAR_LENGTH(l.digitos) = 14 AND NOT EXISTS (SELECT 1 FROM clientes c WHERE c.cnpj_digitos LIKE CONCAT(LEFT(l.digitos, 8), '%')))
                   OR (CHAR_LENGTH(l.digitos) <> 14 AND NOT EXISTS (SELECT 1 FROM clientes c WHERE c.cnpj_digitos = l.digitos))
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

    /** Os filtros de status da tela: o mesmo corte da Carteira, mais `lead`. */
    public const STATUS = ['ativo', 'inativando', 'inativo', 'lead'];

    /**
     * O Resumo: uma linha por segmento com clientes e leads por status, do maior segmento
     * para o menor (é também a ordem das abas). Uma varredura da tabela.
     *
     * ⚠️ Cada número é o tile da aba daquele segmento — mesma tabela, mesma conta. Cliente
     * com filiais em dois segmentos conta nos dois, como em `/carteira?segmento=X`.
     *
     * @return list<array{codigo: string, nome: string, total: int, clientes: int, leads: int, ativo: int, inativando: int, inativo: int, lead: int}>
     */
    public function resumo(): array
    {
        [$ativo, $inativando] = $this->limites();

        $porSegmento = $this->selecionarContagens(DB::table('visao_diretor_universo'), $ativo, $inativando)
            ->addSelect('segmento_codigo')
            ->groupBy('segmento_codigo')
            ->get()
            ->keyBy('segmento_codigo');

        return Segmento::query()->get(['codigo', 'nome'])
            ->map(fn (Segmento $s) => [
                'codigo' => (string) $s->codigo,
                'nome' => $s->nome,
                ...$this->contagensDe($porSegmento->get((string) $s->codigo)),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Uma página da tabela do segmento: clientes e leads misturados, do maior capital
     * social ao menor.
     *
     * @param  array{status?: string, busca?: string, uf?: string, pagina?: int}  $filtros
     */
    public function lista(string $segmento, array $filtros = []): array
    {
        $status = $this->status($filtros['status'] ?? '');
        $pagina = max(1, (int) ($filtros['pagina'] ?? 1));
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $uf = (string) ($filtros['uf'] ?? '');

        $base = $this->base($segmento, $busca, $uf);

        /*
         * As contagens IGNORAM o filtro de status (os tiles são o próprio filtro: um filtro
         * não se aplica à faceta que controla — mesma regra do card da Carteira). Busca e
         * UF continuam valendo.
         */
        [$ativo, $inativando] = $this->limites();
        $contagens = $this->contagensDe($this->selecionarContagens(clone $base, $ativo, $inativando)->first());

        $filtrada = clone $base;
        $this->aplicarStatus($filtrada, $status);

        $linhas = $this->ordenar($filtrada)
            ->offset(($pagina - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA)
            ->get();

        return [
            'segmento' => $segmento,
            // Paginador do Laravel, no formato que `Pagination.vue` já consome. Quem chama
            // decide o caminho dos links (`withPath`); o parâmetro da página é `pagina`.
            'linhas' => new LengthAwarePaginator(
                $this->montarLinhas($linhas),
                $status === '' ? $contagens['total'] : $contagens[$status],
                self::POR_PAGINA,
                $pagina,
                ['pageName' => 'pagina'],
            ),
            'contagens' => $contagens,
            'filtros' => ['status' => $status, 'busca' => $busca, 'uf' => $uf],
            'reconstruidoEm' => $this->reconstruidoEm()?->toIso8601String(),
        ];
    }

    /**
     * A tabela inteira, sem paginar, na ordem da tela — para o Excel. Mesmos filtros de
     * `lista()`, em lotes de 1.000.
     *
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function tudo(string $segmento, array $filtros = []): array
    {
        $query = $this->base($segmento, trim((string) ($filtros['busca'] ?? '')), (string) ($filtros['uf'] ?? ''));
        $this->aplicarStatus($query, $this->status($filtros['status'] ?? ''));

        $linhas = [];
        $this->ordenar($query)->chunk(1000, function (Collection $lote) use (&$linhas) {
            array_push($linhas, ...$this->montarLinhas($lote));
        });

        return $linhas;
    }

    /** UFs que aparecem na tabela (opções do filtro do topo). */
    public function ufs(): array
    {
        return DB::table('visao_diretor_universo')->whereNotNull('uf')->distinct()->orderBy('uf')->pluck('uf')->all();
    }

    /** Valor do filtro de status, pela whitelist. Desconhecido vira "sem filtro". */
    public function status(string $valor): string
    {
        return in_array($valor, self::STATUS, true) ? $valor : '';
    }

    /** Do maior capital ao menor; `id` desempata no mesmo sentido (o índice atende os dois). */
    private function ordenar(Builder $query): Builder
    {
        return $query->orderByDesc('capital_ordem')->orderByDesc('id');
    }

    /** Os clientes e leads do segmento, com busca e UF. */
    private function base(string $segmento, string $busca, string $uf): Builder
    {
        $query = DB::table('visao_diretor_universo')->where('segmento_codigo', $segmento);

        if ($uf !== '') {
            $query->where('uf', $uf);
        }

        if ($busca !== '') {
            // Começo de palavra (`TermoDeBusca`). Documento é gravado com máscara.
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

        match ($status) {
            'lead' => $query->where('tipo', 'lead'),
            'ativo' => $query->where('tipo', 'cliente')->where('data_ultima_compra', '>=', $ativo),
            'inativando' => $query->where('tipo', 'cliente')
                ->where('data_ultima_compra', '<', $ativo)
                ->where('data_ultima_compra', '>=', $inativando),
            'inativo' => $query->where('tipo', 'cliente')
                ->where(fn (Builder $d) => $d->whereNull('data_ultima_compra')->orWhere('data_ultima_compra', '<', $inativando)),
        };
    }

    /** As colunas de contagem por status — a mesma conta de `aplicarStatus()`. */
    private function selecionarContagens(Builder $query, string $ativo, string $inativando): Builder
    {
        return $query
            ->selectRaw("SUM(tipo = 'cliente') as clientes")
            ->selectRaw("SUM(tipo = 'lead') as leads")
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra >= ?) as ativo", [$ativo])
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra < ? AND data_ultima_compra >= ?) as inativando", [$ativo, $inativando])
            ->selectRaw("SUM(tipo = 'cliente' AND (data_ultima_compra IS NULL OR data_ultima_compra < ?)) as inativo", [$inativando]);
    }

    /** @return array{total: int, clientes: int, leads: int, ativo: int, inativando: int, inativo: int, lead: int} */
    private function contagensDe(?object $r): array
    {
        $c = [
            'clientes' => (int) ($r->clientes ?? 0),
            'leads' => (int) ($r->leads ?? 0),
            'ativo' => (int) ($r->ativo ?? 0),
            'inativando' => (int) ($r->inativando ?? 0),
            'inativo' => (int) ($r->inativo ?? 0),
        ];

        return ['total' => $c['clientes'] + $c['leads'], ...$c, 'lead' => $c['leads']];
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
     * As linhas da tela e do Excel, com o nome do vendedor. Uma consulta para o lote inteiro
     * (30 na tela, 1.000 por lote no Excel), nunca por linha.
     *
     * @param  Collection<int, object>  $linhas
     */
    public function montarLinhas(Collection $linhas): array
    {
        $nomes = $this->nomeVendedor->porCodigo($linhas->pluck('cod_vendedor')->filter()->unique());
        $hoje = Carbon::now();

        /*
         * Grupo do TOTVS da matriz (âncora) de cada empresa. Lido aqui, uma consulta por
         * lote, e não gravado na tabela: renomear um grupo vale no request seguinte.
         * CLIENTES DIVERSOS (9998) é "sem grupo" e sai vazio.
         */
        $grupos = DB::table('clientes as a')
            ->join('grupos_cliente as g', 'g.codigo', '=', 'a.cod_grupo')
            ->whereIn('a.id', $linhas->pluck('cliente_id')->filter()->all())
            ->where('a.cod_grupo', '<>', GrupoCliente::SEM_GRUPO)
            ->pluck('g.nome', 'a.id');

        return $linhas->map(function ($l) use ($nomes, $hoje, $grupos) {
            $ehCliente = $l->tipo === 'cliente';
            $uc = $l->data_ultima_compra ? Carbon::parse($l->data_ultima_compra) : null;
            $digitos = preg_replace('/\D/', '', (string) $l->documento);

            return [
                'chave' => "{$l->tipo}-{$l->id}",
                'tipo' => $l->tipo,
                // A âncora (matriz) e o lead: são o que os botões de cartão CNPJ e mapa usam.
                'clienteId' => $l->cliente_id,
                'leadId' => $l->lead_id,
                'temCnpj' => strlen($digitos) === 14,
                /*
                 * A linha do cliente é a EMPRESA (raiz do CNPJ), que pode ter mais de um
                 * código no TOTVS. A Carteira busca CNPJ por trecho, então a raiz mascarada
                 * ("45.543.915") abre todas as filiais dela; sem CNPJ, o código.
                 */
                'buscaCarteira' => strlen($digitos) === 14 ? substr((string) $l->documento, 0, 10) : $l->cod_cliente,
                'nome' => $l->nome,
                'razaoSocial' => $l->razao_social,
                // Fantasia só quando diz algo a mais que a razão social.
                'nomeFantasia' => $l->nome_fantasia && mb_strtoupper($l->nome_fantasia) !== mb_strtoupper($l->nome) ? $l->nome_fantasia : null,
                'documento' => $l->documento,
                'uf' => $l->uf,
                // Filiais do cliente NESTE segmento. Lead não tem filial nossa: nulo ("—").
                'filiais' => $ehCliente ? (int) $l->lojas : null,
                'status' => $ehCliente ? $this->statusResolver->statusPara($uc, $hoje) : 'lead',
                'grupo' => $l->cliente_id ? ($grupos[$l->cliente_id] ?? null) : null,
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
