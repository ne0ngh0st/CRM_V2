-- ============================================================================
-- Definição das views do BI no legado (autopel01, MariaDB, KingHost)
--
-- Copiado do phpMyAdmin (information_schema.VIEWS / SHOW CREATE VIEW) em
-- 2026-09-16, antes do desligamento do autopel01. Só referência: é o que as
-- views bi.vw_bi_* do CRM-V2 substituem. As outras três views
-- (vw_bi_dim_cliente, vw_bi_fato_faturamento, vw_bi_fato_pedidos_abertos)
-- estão em POWER BI\old\municipio_no_bi.sql.
--
-- Os aliases estranhos da segunda metade de vw_bi_dim_vendedor
-- (`CONCAT('NAO CADASTRADO (', ...)`, `NULL`, `0`) são como o MariaDB
-- reescreve um UNION: o nome da coluna vem sempre da primeira metade.
-- ============================================================================


-- vw_bi_seg_acesso
select distinct lcase(`u`.`EMAIL`) AS `email`,`v`.`cod_vendedor` AS `cod_vendedor` from (`autopel01`.`USUARIOS` `u` join `autopel01`.`vw_bi_dim_vendedor` `v` on(`u`.`PERFIL` in ('diretor','admin') or `v`.`cod_vendedor` = `u`.`COD_VENDEDOR` or `v`.`cod_supervisor` = `u`.`COD_VENDEDOR` or lpad(cast(`v`.`cod_gerente` as unsigned),6,'0') = `u`.`COD_VENDEDOR`)) where `u`.`ATIVO` = 1 and `u`.`EMAIL` like '%@autopel.com';


-- vw_bi_fato_leads
select 'BASE' AS `origem`,`autopel01`.`BASE_LEADS`.`cnpj` AS `cnpj`,`autopel01`.`BASE_LEADS`.`raizCNPJ` AS `raiz_cnpj`,`autopel01`.`BASE_LEADS`.`RAZAOSOCIAL` AS `razao_social`,`autopel01`.`BASE_LEADS`.`NOMEFANTASIA` AS `nome_fantasia`,`autopel01`.`BASE_LEADS`.`UF` AS `uf`,`autopel01`.`BASE_LEADS`.`CIDADE` AS `cidade`,`autopel01`.`BASE_LEADS`.`Faturamentobrutoem2024R` AS `faturamento_bruto_2024`,`autopel01`.`BASE_LEADS`.`CodigoVendedor` AS `cod_vendedor`,`autopel01`.`BASE_LEADS`.`status` AS `status`,`autopel01`.`BASE_LEADS`.`datadaultimavenda` AS `data_ultima_venda`,`autopel01`.`BASE_LEADS`.`valordaultimavenda` AS `valor_ultima_venda` from `autopel01`.`BASE_LEADS` union all select 'MANUAL' AS `origem`,`autopel01`.`LEADS_MANUAIS`.`cnpj` AS `cnpj`,NULL AS `raiz_cnpj`,`autopel01`.`LEADS_MANUAIS`.`razao_social` AS `razao_social`,`autopel01`.`LEADS_MANUAIS`.`nome_fantasia` AS `nome_fantasia`,`autopel01`.`LEADS_MANUAIS`.`estado` AS `uf`,`autopel01`.`LEADS_MANUAIS`.`municipio` AS `cidade`,`autopel01`.`LEADS_MANUAIS`.`valor_estimado` AS `faturamento_bruto_2024`,`autopel01`.`LEADS_MANUAIS`.`codigo_vendedor` AS `cod_vendedor`,`autopel01`.`LEADS_MANUAIS`.`status` AS `status`,NULL AS `data_ultima_venda`,NULL AS `valor_ultima_venda` from `autopel01`.`LEADS_MANUAIS`;


-- vw_bi_dim_produto
select `autopel01`.`CODIGO_PRODUTOS`.`COD_PROD` AS `cod_produto`,`autopel01`.`CODIGO_PRODUTOS`.`DESC_PROD` AS `descricao`,`autopel01`.`CODIGO_PRODUTOS`.`UN_PROD` AS `unidade`,`autopel01`.`CODIGO_PRODUTOS`.`PRCVENDA` AS `preco_venda`,`autopel01`.`CODIGO_PRODUTOS`.`CAT_PROD` AS `categoria` from `autopel01`.`CODIGO_PRODUTOS`;


-- vw_bi_dim_vendedor
select `x`.`cod_vendedor` AS `cod_vendedor`,`x`.`nome` AS `nome`,`x`.`nome_exibicao` AS `nome_exibicao`,`x`.`perfil` AS `perfil`,`x`.`cod_supervisor` AS `cod_supervisor`,`x`.`cod_gerente` AS `cod_gerente`,`x`.`equipe` AS `equipe`,`x`.`ativo` AS `ativo` from (select `u`.`COD_VENDEDOR` AS `cod_vendedor`,`u`.`NOME_COMPLETO` AS `nome`,`u`.`NOME_EXIBICAO` AS `nome_exibicao`,`u`.`PERFIL` AS `perfil`,`u`.`COD_SUPER` AS `cod_supervisor`,`u`.`COD_GERENTE` AS `cod_gerente`,`u`.`EQUIPE_REP` AS `equipe`,`u`.`ATIVO` AS `ativo` from `autopel01`.`USUARIOS` `u` where `u`.`COD_VENDEDOR` is not null and `u`.`COD_VENDEDOR` <> '' union all select `e`.`cod_vendedor` AS `cod_vendedor`,concat('NAO CADASTRADO (',`e`.`cod_vendedor`,')') AS `CONCAT('NAO CADASTRADO (', e.cod_vendedor, ')')`,concat('NAO CADASTRADO (',`e`.`cod_vendedor`,')') AS `CONCAT('NAO CADASTRADO (', e.cod_vendedor, ')')`,'nao_cadastrado' AS `nao_cadastrado`,NULL AS `NULL`,NULL AS `NULL`,NULL AS `NULL`,0 AS `0` from `autopel01`.`bi_dim_vendedor_extras` `e` where `e`.`usar` = 1 and !(`e`.`cod_vendedor` in (select `autopel01`.`USUARIOS`.`COD_VENDEDOR` from `autopel01`.`USUARIOS` where `autopel01`.`USUARIOS`.`COD_VENDEDOR` is not null and `autopel01`.`USUARIOS`.`COD_VENDEDOR` <> ''))) `x`;


-- vw_bi_fato_orcamentos
select `autopel01`.`ORCAMENTOS`.`id` AS `id_orcamento`,`autopel01`.`ORCAMENTOS`.`codigo_cliente` AS `cod_cliente`,`autopel01`.`ORCAMENTOS`.`cliente_cnpj` AS `cnpj`,`autopel01`.`ORCAMENTOS`.`cliente_nome` AS `cliente_nome`,`autopel01`.`ORCAMENTOS`.`cliente_razao_social` AS `cliente_razao_social`,`autopel01`.`ORCAMENTOS`.`codigo_vendedor` AS `cod_vendedor`,`autopel01`.`ORCAMENTOS`.`tipo_produto_servico` AS `tipo_produto_servico`,`autopel01`.`ORCAMENTOS`.`valor_total` AS `valor_total`,`autopel01`.`ORCAMENTOS`.`status` AS `status`,`autopel01`.`ORCAMENTOS`.`status_cliente` AS `status_cliente`,`autopel01`.`ORCAMENTOS`.`status_gestor` AS `status_gestor`,`autopel01`.`ORCAMENTOS`.`motivo_recusa` AS `motivo_recusa`,`autopel01`.`ORCAMENTOS`.`forma_pagamento` AS `forma_pagamento`,`autopel01`.`ORCAMENTOS`.`tipo_faturamento` AS `tipo_faturamento`,`autopel01`.`ORCAMENTOS`.`origem_cliente` AS `origem_cliente`,`autopel01`.`ORCAMENTOS`.`data_criacao` AS `data_criacao`,`autopel01`.`ORCAMENTOS`.`data_validade` AS `data_validade`,`autopel01`.`ORCAMENTOS`.`data_aprovacao_cliente` AS `data_aprovacao_cliente`,`autopel01`.`ORCAMENTOS`.`data_aprovacao_gestor` AS `data_aprovacao_gestor` from `autopel01`.`ORCAMENTOS`;


-- vw_bi_fato_indicadores_municipio
select `i`.`cod_municipio` AS `cod_municipio`,`i`.`pea_total` AS `pea`,`i`.`ano_base_pea` AS `ano_base_pea`,`i`.`populacao` AS `populacao`,`i`.`ano_base_pop` AS `ano_base_pop`,`i`.`pib_total_reais` AS `pib_total`,`i`.`ano_base_pib` AS `ano_base_pib`,`i`.`num_supermercados` AS `num_supermercados`,`i`.`supermercados_pessoal_ocupado` AS `supermercados_pessoal_ocupado`,`i`.`supermercados_massa_salarial_reais` AS `supermercados_massa_salarial`,`i`.`ano_base_supermercados` AS `ano_base_supermercados`,case when `i`.`populacao` > 0 then `i`.`pib_total_reais` / `i`.`populacao` end AS `pib_per_capita`,case when `i`.`populacao` > 0 then `i`.`pea_total` / `i`.`populacao` end AS `pea_percentual_populacao`,`i`.`atualizado_em` AS `atualizado_em` from `autopel01`.`IBGE_INDICADORES_MUNICIPIO` `i`;


-- vw_bi_dim_geografia
select `m`.`cod_municipio` AS `cod_municipio`,`m`.`nome_municipio` AS `cidade`,`m`.`cod_micro` AS `cod_micro`,`m`.`nome_micro` AS `microrregiao`,`m`.`cod_meso` AS `cod_meso`,`m`.`nome_meso` AS `mesorregiao`,`m`.`cod_uf` AS `cod_uf`,`m`.`sigla_uf` AS `uf`,`m`.`cod_regiao` AS `cod_regiao`,`m`.`nome_regiao` AS `regiao` from `autopel01`.`IBGE_MUNICIPIOS` `m`;
