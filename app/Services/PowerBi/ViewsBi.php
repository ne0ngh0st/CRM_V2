<?php

namespace App\Services\PowerBi;

use Illuminate\Support\Facades\DB;

/**
 * As views `vw_bi_*` que o Power BI lê, no schema `bi`.
 *
 * Substituem as do `autopel01` (KingHost) com os MESMOS nomes e colunas — o modelo
 * (`BI_RADES`) só troca a fonte de dados, e medidas e visuais continuam casando pelo
 * nome. O que o legado fazia está em `database/dados-bi/views-legado.sql` e em
 * `POWER BI\old\municipio_no_bi.sql`; as diferenças deliberadas estão comentadas em
 * cada view.
 *
 * ⚠️ ÚNICO LUGAR onde a SQL das views mora. A migration e o `bi:recriar-views` chamam
 * `recriar()`; nenhuma definição é copiada para migration. Motivo extra, além da Regra
 * de ouro nº 8: a view grava o NOME do banco do app (`palma_v2` no dev,
 * `palma_v2_test` na suíte), então uma cópia literal só serviria a um dos dois.
 * Mudou uma view? Nova migration chamando `ViewsBi::recriar()`, ou `bi:recriar-views`.
 *
 * ⚠️ `SQL SECURITY INVOKER` em todas: a view roda com a permissão de quem CONSULTA. O
 * `bi_leitura` precisa de SELECT nas tabelas de baixo (`SchemaBi::TABELAS_DO_APP`), e
 * em troca a view não fica presa a um DEFINER — era isso que quebrava no legado quando
 * o IP do usuário definidor mudava.
 *
 * Convenções de todas as views:
 * - CNPJ sai só com dígitos (e `raiz_cnpj` = 8 primeiros dígitos, CALCULADO aqui —
 *   a Regra de ouro nº 3 proíbe guardá-lo, não derivá-lo numa consulta);
 * - datas saem como DATE/DATETIME, não texto no formato do TOTVS;
 * - status de cliente usa os cortes do BI legado, 295 e 365 dias. ⚠️ A tela da
 *   Carteira usa 290 (ClienteStatusResolver) — manter 295 aqui foi escolha para o BI
 *   não mudar de número na virada. Unificar é decisão de negócio, não desta camada.
 */
class ViewsBi
{
    /** Ordem de criação: quem é usado por outra view vem antes. */
    public const VIEWS = [
        'vw_bi_dim_vendedor',
        'vw_bi_dim_cliente',
        'vw_bi_dim_produto',
        'vw_bi_dim_geografia',
        'vw_bi_fato_faturamento',
        'vw_bi_fato_pedidos_abertos',
        'vw_bi_fato_pedidos_emitidos',
        'vw_bi_fato_orcamentos',
        'vw_bi_fato_leads',
        'vw_bi_fato_indicadores_municipio',
        'vw_bi_potencial_estado',
        'vw_bi_fato_metas',
        'vw_bi_seg_acesso',
    ];

    public static function recriar(): void
    {
        SchemaBi::exigir();

        foreach (self::definicoes() as $view => $select) {
            DB::statement('CREATE OR REPLACE SQL SECURITY INVOKER VIEW '.SchemaBi::tabela($view)." AS {$select}");
        }
    }

    public static function remover(): void
    {
        foreach (array_reverse(self::VIEWS) as $view) {
            DB::statement('DROP VIEW IF EXISTS '.SchemaBi::tabela($view));
        }
    }

    /** @return array<string, string> view => SELECT, na ordem de criação */
    public static function definicoes(): array
    {
        $definicoes = [];

        foreach (self::VIEWS as $view) {
            // vw_bi_dim_vendedor → vwBiDimVendedor
            $metodo = lcfirst(str_replace('_', '', ucwords($view, '_')));
            $definicoes[$view] = self::$metodo();
        }

        return $definicoes;
    }

    // ─── Expressões compartilhadas ──────────────────────────────────────────────

    /**
     * Só os dígitos de um CNPJ/CPF. As máscaras da base usam apenas `.`, `/` e `-`
     * (medido em 2026-09-16 em clientes, faturamentos, orçamentos e leads), e o REPLACE
     * encadeado custa bem menos que REGEXP_REPLACE nas 6 M de linhas do faturamento.
     */
    private static function digitos(string $coluna): string
    {
        return "REPLACE(REPLACE(REPLACE({$coluna}, '.', ''), '/', ''), '-', '')";
    }

    /**
     * Nome de município no formato de `de_para_municipio.nome_norm`: maiúsculas, hífen
     * e apóstrofo viram espaço, espaço duplo colapsado. Cópia exata da normalização
     * que gerou o de-para no legado — mudar um lado sem o outro zera a cobertura.
     */
    private static function nomeMunicipio(string $coluna): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM({$coluna})), '-', ' '), '''', ' '), '  ', ' '), '  ', ' ')";
    }

    private static function statusCliente(string $data): string
    {
        return "CASE
            WHEN {$data} IS NULL THEN 'nunca comprou'
            WHEN DATEDIFF(CURDATE(), {$data}) > 365 THEN 'inativo'
            WHEN DATEDIFF(CURDATE(), {$data}) > 295 THEN 'inativando'
            ELSE 'ativo'
        END";
    }

    private static function joinMunicipio(string $alias, string $uf, string $municipio): string
    {
        return 'LEFT JOIN '.SchemaBi::tabela('de_para_municipio')." {$alias}
            ON {$alias}.uf = {$uf} AND {$alias}.nome_norm = ".self::nomeMunicipio($municipio);
    }

    private static function a(string $tabela): string
    {
        return SchemaBi::app($tabela);
    }

    // ─── Dimensões ──────────────────────────────────────────────────────────────

    /**
     * Uma linha por código de vendedor.
     *
     * O legado lia `USUARIOS` + uma tabela manual de "não cadastrados"; aqui a lista de
     * códigos é a do TOTVS (`vendedores_totvs`), que já tem nome para todos, somada aos
     * códigos que só existem no CRM.
     *
     * ⚠️ O mesmo código pode estar em mais de um usuário (134 perfis para 132 códigos em
     * 2026-09-16). Fica o usuário ATIVO; empatando, o mais antigo. No legado quem
     * desempatava eram filtros fixos por nome dentro do Power BI — com esta regra eles
     * deixam de ser necessários.
     */
    private static function vwBiDimVendedor(): string
    {
        return "
            SELECT
                c.codigo AS cod_vendedor,
                COALESCE(u.name, vt.nome, CONCAT('NAO CADASTRADO (', c.codigo, ')')) AS nome,
                COALESCE(u.display_name, vt.nome_reduzido, vt.nome, CONCAT('NAO CADASTRADO (', c.codigo, ')')) AS nome_exibicao,
                COALESCE(r.perfil, 'nao_cadastrado') AS perfil,
                vp.cod_super AS cod_supervisor,
                vp.cod_gerente AS cod_gerente,
                vp.equipe_rep AS equipe,
                COALESCE(u.is_active, 0) AS ativo
            FROM (
                SELECT codigo FROM ".self::a('vendedores_totvs')."
                UNION
                SELECT cod_vendedor FROM ".self::a('vendedor_perfis')." WHERE cod_vendedor <> ''
            ) c
            LEFT JOIN (
                SELECT p.*, ROW_NUMBER() OVER (PARTITION BY p.cod_vendedor ORDER BY uu.is_active DESC, uu.id) AS ordem
                FROM ".self::a('vendedor_perfis').' p
                JOIN '.self::a('users').' uu ON uu.id = p.user_id
            ) vp ON vp.cod_vendedor = c.codigo AND vp.ordem = 1
            LEFT JOIN '.self::a('users').' u ON u.id = vp.user_id
            LEFT JOIN '.self::a('vendedores_totvs').' vt ON vt.codigo = c.codigo
            LEFT JOIN '.self::papeis().' r ON r.user_id = u.id';
    }

    /** Papel do spatie por usuário. Usuário comum tem um só; havendo mais, o primeiro em ordem alfabética. */
    private static function papeis(): string
    {
        return '(
            SELECT mr.model_id AS user_id, MIN(ro.name) AS perfil
            FROM '.self::a('model_has_roles').' mr
            JOIN '.self::a('roles')." ro ON ro.id = mr.role_id
            WHERE mr.model_type = 'App\\\\Models\\\\User'
            GROUP BY mr.model_id
        )";
    }

    /**
     * ⚠️ `grupo_vendas` é inteiro no modelo; `clientes.cod_grupo` é texto, mas 100% numérico
     * (conferido em 2026-09-16) — o CAST é seguro e a regex protege do dia em que não for.
     */
    private static function vwBiDimCliente(): string
    {
        return '
            SELECT
                c.cod_cliente,
                c.loja,
                '.self::digitos('c.cnpj').' AS cnpj,
                LEFT('.self::digitos('c.cnpj').', 8) AS raiz_cnpj,
                c.razao_social,
                c.nome_fantasia,
                c.cod_vendedor,
                c.cod_segmento,
                c.estado,
                CASE WHEN c.cod_grupo REGEXP \'^[0-9]+$\' THEN CAST(c.cod_grupo AS UNSIGNED) END AS grupo_vendas,
                g.nome AS grupo_descricao,
                s.nome AS segmento_descricao,
                c.data_ultima_compra,
                '.self::statusCliente('c.data_ultima_compra').' AS status,
                c.municipio AS municipio_origem,
                dp.cod_municipio
            FROM '.self::a('clientes').' c
            LEFT JOIN '.self::a('grupos_cliente').' g ON g.codigo = c.cod_grupo
            LEFT JOIN '.self::a('segmentos').' s ON s.codigo = c.cod_segmento
            '.self::joinMunicipio('dp', 'c.estado', 'c.municipio');
    }

    /**
     * Cadastro de produtos + os códigos que aparecem em venda e não estão no cadastro.
     *
     * ⚠️ `produtos` hoje só é alimentado pelo `legado:import-produtos`, que depende do
     * legado. Sem os órfãos, um produto novo vendido depois do desligamento sumiria da
     * dimensão e as linhas de faturamento dele ficariam sem descrição no BI. (O legado
     * não tinha órfãos nenhum: esta parte é nova.)
     *
     * ⚠️ É a view mais cara depois da de faturamento, e a forma importa — medido no dev,
     * sobre 6 M de linhas:
     *   - filtrar os órfãos DEPOIS do UNION: 28 s (o UNION ALL materializa tudo antes);
     *   - agrupar antes de filtrar, com MAX(descrição): 56 s;
     *   - filtrar primeiro e agrupar cada ramo pelo código cru, com ANY_VALUE: ~12 s.
     * O código já chega aparado pelo import e a collation ignora caixa, então UPPER/TRIM
     * na chave só custava — a deduplicação por `cod_norm` continua no modelo.
     */
    private static function vwBiDimProduto(): string
    {
        return '
            SELECT p.cod_produto, p.descricao, p.unidade, p.preco_tabela AS preco_venda, p.categoria
            FROM '.self::a('produtos').' p
            UNION ALL
            SELECT o.cod_produto, MAX(o.descricao), NULL, NULL, NULL
            FROM (
                SELECT f.cod_produto, ANY_VALUE(f.produto_desc) AS descricao
                FROM '.self::a('faturamentos').' f
                WHERE f.cod_produto IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM '.self::a('produtos').' px WHERE px.cod_produto = f.cod_produto)
                GROUP BY f.cod_produto
                UNION ALL
                SELECT i.cod_produto, ANY_VALUE(i.descricao)
                FROM '.self::a('pedido_itens').' i
                WHERE i.cod_produto IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM '.self::a('produtos').' px WHERE px.cod_produto = i.cod_produto)
                GROUP BY i.cod_produto
            ) o
            GROUP BY o.cod_produto';
    }

    private static function vwBiDimGeografia(): string
    {
        return '
            SELECT
                m.cod_municipio,
                m.nome_municipio AS cidade,
                m.cod_micro,
                m.nome_micro AS microrregiao,
                m.cod_meso,
                m.nome_meso AS mesorregiao,
                m.cod_uf,
                m.sigla_uf AS uf,
                m.cod_regiao,
                m.nome_regiao AS regiao
            FROM '.SchemaBi::tabela('ibge_municipios').' m';
    }

    // ─── Fatos ──────────────────────────────────────────────────────────────────

    /**
     * ⚠️ O Power BI filtra por `data_emissao` no refresh incremental; a coluna sai crua
     * (sem função em volta) justamente para o filtro alcançar `fat_data_valor_idx`.
     *
     * `origem_tabela` era o nome da tabela-por-ano do legado; aqui só existe uma.
     */
    private static function vwBiFatoFaturamento(): string
    {
        return "
            SELECT
                'FATURAMENTO' AS origem_tabela,
                f.filial,
                f.cod_cliente,
                f.loja,
                ".self::digitos('f.cnpj').' AS cnpj,
                LEFT('.self::digitos('f.cnpj').', 8) AS raiz_cnpj,
                f.cod_produto,
                f.cod_vendedor,
                f.segmento,
                f.data_emissao,
                f.pedido AS numero_pedido,
                f.quantidade,
                f.valor_unitario,
                f.valor_total,
                CASE WHEN f.valor_total < 0 THEN 1 ELSE 0 END AS eh_devolucao,
                f.desc_familia,
                f.municipio AS municipio_origem,
                dp.cod_municipio
            FROM '.self::a('faturamentos').' f
            '.self::joinMunicipio('dp', 'f.estado', 'f.municipio');
    }

    /**
     * Uma linha por ITEM de pedido em aberto, como a `PEDIDOS_EM_ABERTO` do legado.
     *
     * Diferenças deliberadas:
     * - loja, CNPJ e município vêm do cadastro do cliente (`pedidos` guarda só o
     *   `cliente_id`); pedido sem cliente no cadastro sai com essas colunas nulas;
     * - `atraso` é calculado: dias desde a previsão de faturamento, nunca negativo — a
     *   mesma régua de "atrasado" do painel do CRM;
     * - `condicao_pagamento` vira número ("060" → 60), que é o tipo do modelo.
     */
    private static function vwBiFatoPedidosAbertos(): string
    {
        return '
            SELECT
                p.filial,
                c.cod_cliente,
                c.loja,
                '.self::digitos('c.cnpj').' AS cnpj,
                p.cod_vendedor,
                i.cod_produto,
                p.numero_pedido,
                p.data_pedido,
                p.data_entrega_prevista AS data_entrega,
                p.data_previsao_faturamento,
                p.data_pcp,
                GREATEST(DATEDIFF(CURDATE(), p.data_previsao_faturamento), 0) AS atraso,
                CASE WHEN p.condicao_pagamento REGEXP \'^[0-9]+$\' THEN CAST(p.condicao_pagamento AS UNSIGNED) END AS condicao_pagamento,
                i.quantidade AS quantidade_venda,
                i.quantidade_liberada,
                i.valor_total,
                c.municipio AS municipio_origem,
                dp.cod_municipio
            FROM '.self::a('pedidos').' p
            JOIN '.self::a('pedido_itens').' i ON i.pedido_id = p.id
            LEFT JOIN '.self::a('clientes').' c ON c.id = p.cliente_id
            '.self::joinMunicipio('dp', 'c.estado', 'c.municipio').'
            WHERE p.data_faturamento IS NULL';
    }

    /**
     * Substitui o SELECT direto em `META_VENDA` (relatório 232). Uma linha por item de
     * TODO pedido emitido — em aberto e faturado; `DT_FATURAMENTO` nulo é o em aberto.
     * Os nomes em maiúsculas são os do SELECT antigo, que o modelo usa.
     *
     * - `ATIVIDADE` = nome do segmento do cliente;
     * - `REPRES` = nome do vendedor do pedido; `SUPERVISOR` = nome do supervisor dele
     *   (decisão do Tony, 2026-09-16) — os dois pela `vw_bi_dim_vendedor`, para o nome
     *   ser o mesmo da dimensão.
     */
    private static function vwBiFatoPedidosEmitidos(): string
    {
        /*
         * ⚠️ A dimensão entra por uma derivada com GROUP BY, e não direto. Direto, o MySQL
         * funde a vw_bi_dim_vendedor na consulta e refaz a numeração dos perfis
         * (ROW_NUMBER) para CADA item de pedido, duas vezes: 39 s para 603 mil linhas no
         * dev. Com GROUP BY a fusão é impossível — a dimensão é calculada uma vez e ganha
         * índice automático no código. O MAX não altera nada: a dimensão já tem uma linha
         * por código. (O hint NO_MERGE foi tentado antes e é ignorado dentro de view.)
         */
        $vendedor = '(SELECT cod_vendedor, MAX(nome) AS nome, MAX(cod_supervisor) AS cod_supervisor
            FROM '.SchemaBi::tabela('vw_bi_dim_vendedor').' GROUP BY cod_vendedor)';

        return '
            SELECT
                p.numero_pedido AS PEDIDO,
                p.data_pedido AS dt_emissao_date,
                '.self::digitos('c.cnpj').' AS CNPJ,
                s.nome AS ATIVIDADE,
                p.cod_vendedor AS COD_VENDEDOR,
                v.nome AS REPRES,
                i.cod_produto AS COD_PROD,
                i.descricao AS DESC_PROD,
                pr.unidade AS UND,
                i.peso_liquido AS PESO_LIQ,
                i.valor_unitario AS PRC_VENDA,
                i.quantidade AS QTDA_VENDA,
                i.valor_total AS VLR_TOTAL,
                p.data_faturamento AS DT_FATURAMENTO,
                i.nota_fiscal AS NUM_DOCTO,
                p.filial AS FILIAL,
                sup.nome AS SUPERVISOR,
                c.razao_social AS CLIENTE,
                p.data_previsao_faturamento AS PREV_FAT
            FROM '.self::a('pedidos').' p
            JOIN '.self::a('pedido_itens').' i ON i.pedido_id = p.id
            LEFT JOIN '.self::a('clientes').' c ON c.id = p.cliente_id
            LEFT JOIN '.self::a('segmentos').' s ON s.codigo = c.cod_segmento
            LEFT JOIN '.self::a('produtos')." pr ON pr.cod_produto = i.cod_produto
            LEFT JOIN {$vendedor} v ON v.cod_vendedor = p.cod_vendedor
            LEFT JOIN {$vendedor} sup ON sup.cod_vendedor = v.cod_supervisor";
    }

    /**
     * ⚠️ `status`, `status_cliente`, `tipo_faturamento` e `data_aprovacao_cliente` saem
     * NULOS de propósito (decisão do Tony, 2026-09-16). No legado `status = 'aprovado'`
     * era o cliente aceitando — é o que a medida "Taxa de Conversao de Orcamentos" conta.
     * O CRM-V2 não tem aprovação do cliente, e usar a aprovação interna do gestor
     * inflaria a conversão com outro significado. A medida fica em branco até existir
     * um dado real de conversão.
     *
     * `cod_vendedor` é o de quem criou o orçamento; `origem_cliente` diz se ele nasceu de
     * um cliente da carteira, de um lead ou avulso.
     */
    private static function vwBiFatoOrcamentos(): string
    {
        return '
            SELECT
                o.id AS id_orcamento,
                c.cod_cliente,
                '.self::digitos('o.cliente_cnpj').' AS cnpj,
                o.cliente_nome,
                COALESCE(c.razao_social, o.cliente_nome) AS cliente_razao_social,
                vp.cod_vendedor,
                o.tipo_produto_servico,
                o.valor_total,
                CAST(NULL AS CHAR(20)) AS status,
                CAST(NULL AS CHAR(20)) AS status_cliente,
                o.status_gestor,
                o.motivo_rejeicao AS motivo_recusa,
                o.forma_pagamento,
                CAST(NULL AS CHAR(20)) AS tipo_faturamento,
                CASE
                    WHEN o.cliente_id IS NOT NULL THEN \'cliente\'
                    WHEN o.lead_id IS NOT NULL THEN \'lead\'
                    ELSE \'avulso\'
                END AS origem_cliente,
                o.created_at AS data_criacao,
                o.data_validade,
                CAST(NULL AS DATETIME) AS data_aprovacao_cliente,
                o.aprovado_em AS data_aprovacao_gestor
            FROM '.self::a('orcamentos').' o
            LEFT JOIN '.self::a('clientes').' c ON c.id = o.cliente_id
            LEFT JOIN '.self::a('vendedor_perfis').' vp ON vp.user_id = o.user_id';
    }

    /**
     * ⚠️ `status` NÃO é a etapa do funil. No legado é a situação do CLIENTE — `ativo`,
     * `inativo`, `inativando` ou `prospect` —, e é isso que a medida "Resumo Clientes
     * Multilinha" conta. CNPJ que existe na carteira ganha o status da última compra
     * (mesma régua da `vw_bi_dim_cliente`); o que não existe é `prospect`.
     *
     * - `origem`: BASE (import do TOTVS/legado), MANUAL (cadastro do vendedor), SITE
     *   (formulário do site — não existia no legado);
     * - `faturamento_bruto_2024`: só os leads manuais têm valor (o estimado), como no
     *   legado; a base importada não trouxe a coluna;
     * - última venda: data e soma do dia da última compra do CNPJ.
     */
    private static function vwBiFatoLeads(): string
    {
        return "
            SELECT
                CASE l.origem WHEN 'sistema' THEN 'BASE' WHEN 'manual' THEN 'MANUAL' ELSE 'SITE' END AS origem,
                ".self::digitos('l.cnpj').' AS cnpj,
                LEFT('.self::digitos('l.cnpj').", 8) AS raiz_cnpj,
                l.razao_social,
                l.nome_fantasia,
                l.estado AS uf,
                l.cidade,
                CASE WHEN l.origem <> 'sistema' THEN l.valor_estimado END AS faturamento_bruto_2024,
                l.cod_vendedor,
                CASE WHEN cl.cnpj IS NULL THEN 'prospect' ELSE ".self::statusCliente('cl.ultima_compra').' END AS status,
                cl.ultima_compra AS data_ultima_venda,
                (
                    SELECT SUM(f.valor_total)
                    FROM '.self::a('faturamentos').' f
                    WHERE f.data_emissao = cl.ultima_compra
                      AND '.self::digitos('f.cnpj').' = cl.cnpj
                ) AS valor_ultima_venda
            FROM '.self::a('leads').' l
            LEFT JOIN (
                SELECT '.self::digitos('cc.cnpj').' AS cnpj, MAX(cc.data_ultima_compra) AS ultima_compra
                FROM '.self::a('clientes').' cc
                WHERE cc.cnpj IS NOT NULL
                GROUP BY '.self::digitos('cc.cnpj').'
            ) cl ON cl.cnpj = '.self::digitos('l.cnpj')."
            WHERE l.status <> 'excluido'";
    }

    private static function vwBiFatoIndicadoresMunicipio(): string
    {
        return '
            SELECT
                i.cod_municipio,
                i.pea_total AS pea,
                i.ano_base_pea,
                i.populacao,
                i.ano_base_pop,
                i.pib_total_reais AS pib_total,
                i.ano_base_pib,
                i.num_supermercados,
                i.supermercados_pessoal_ocupado,
                i.supermercados_massa_salarial_reais AS supermercados_massa_salarial,
                i.ano_base_supermercados,
                CASE WHEN i.populacao > 0 THEN i.pib_total_reais / i.populacao END AS pib_per_capita,
                CASE WHEN i.populacao > 0 THEN i.pea_total / i.populacao END AS pea_percentual_populacao,
                i.atualizado_em
            FROM '.SchemaBi::tabela('indicadores_municipio').' i';
    }

    /** Substitui o SELECT direto em `potencial_mercado_estado`, com as mesmas colunas. */
    private static function vwBiPotencialEstado(): string
    {
        return '
            SELECT
                uf, estado, regiao, populacao, pib_milhoes, pib_per_capita,
                pct_pib_nacional, pct_populacao_nacional, num_pdvs, pct_pdvs_nacional,
                ipm, ranking_potencial, ano_base_pop, ano_base_pib, ano_base_pdvs, atualizado_em
            FROM '.SchemaBi::tabela('potencial_mercado_estado');
    }

    /** Substitui o SELECT em `METAS_MENSAIS`: as metas agora são as da tela `/metas`. */
    private static function vwBiFatoMetas(): string
    {
        return '
            SELECT
                m.id,
                m.cod_vendedor AS COD_VENDEDOR,
                m.ano,
                m.mes,
                m.tipo,
                m.valor_meta,
                m.created_at AS data_criacao,
                m.updated_at AS data_atualizacao
            FROM '.self::a('metas_mensais').' m';
    }

    /**
     * Tabela do RLS: cada linha diz que o e-mail enxerga aquele código de vendedor.
     *
     * Regra do legado, mantida: diretor e admin veem todos; os demais veem o próprio
     * código e quem aponta para ele em `cod_supervisor` ou `cod_gerente` (um nível só);
     * só usuários ativos com e-mail @autopel.com.
     */
    private static function vwBiSegAcesso(): string
    {
        return '
            SELECT DISTINCT LOWER(u.email) AS email, v.cod_vendedor
            FROM '.self::a('users').' u
            LEFT JOIN '.self::a('vendedor_perfis').' vp ON vp.user_id = u.id
            LEFT JOIN '.self::papeis().' r ON r.user_id = u.id
            JOIN '.SchemaBi::tabela('vw_bi_dim_vendedor')." v
                ON r.perfil IN ('diretor', 'admin')
                OR v.cod_vendedor = vp.cod_vendedor
                OR v.cod_supervisor = vp.cod_vendedor
                OR v.cod_gerente = vp.cod_vendedor
            WHERE u.is_active = 1
              AND LOWER(u.email) LIKE '%@autopel.com'";
    }
}
