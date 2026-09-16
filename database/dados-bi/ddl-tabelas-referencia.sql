CREATE TABLE `IBGE_MUNICIPIOS` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cod_municipio` int(11) NOT NULL,
  `nome_municipio` varchar(200) NOT NULL,
  `cod_micro` int(11) NOT NULL,
  `nome_micro` varchar(200) NOT NULL,
  `cod_meso` int(11) NOT NULL,
  `nome_meso` varchar(200) NOT NULL,
  `cod_uf` int(11) NOT NULL,
  `cod_regiao` int(11) DEFAULT NULL,
  `nome_regiao` varchar(50) DEFAULT NULL,
  `sigla_uf` char(2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nome_uf` (`nome_municipio`(100),`sigla_uf`),
  KEY `idx_cod_municipio` (`cod_municipio`),
  KEY `idx_cod_micro` (`cod_micro`),
  KEY `idx_cod_meso` (`cod_meso`),
  KEY `idx_sigla_uf` (`sigla_uf`),
  KEY `idx_cod_regiao` (`cod_regiao`)
) ENGINE=InnoDB AUTO_INCREMENT=5572 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `de_para_municipio` (
  `uf` char(2) NOT NULL,
  `nome_norm` varchar(120) NOT NULL,
  `cod_municipio` int(11) NOT NULL,
  `origem` varchar(20) NOT NULL,
  PRIMARY KEY (`uf`,`nome_norm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `IBGE_INDICADORES_MUNICIPIO` (
  `cod_municipio` int(11) NOT NULL,
  `pea_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ano_base_pea` smallint(6) NOT NULL,
  `populacao` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ano_base_pop` smallint(6) NOT NULL,
  `pib_total_reais` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ano_base_pib` smallint(6) NOT NULL,
  `num_supermercados` int(11) NOT NULL DEFAULT 0,
  `supermercados_pessoal_ocupado` int(11) NOT NULL DEFAULT 0,
  `supermercados_massa_salarial_reais` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ano_base_supermercados` smallint(6) DEFAULT NULL,
  `atualizado_em` datetime NOT NULL,
  PRIMARY KEY (`cod_municipio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `potencial_mercado_estado` (
  `uf` char(2) NOT NULL,
  `estado` varchar(60) NOT NULL,
  `regiao` varchar(20) NOT NULL,
  `populacao` bigint(20) NOT NULL,
  `pib_milhoes` decimal(18,2) NOT NULL,
  `pib_per_capita` decimal(18,6) NOT NULL,
  `pct_pib_nacional` decimal(18,10) NOT NULL,
  `pct_populacao_nacional` decimal(18,10) NOT NULL,
  `num_pdvs` int(11) NOT NULL,
  `pct_pdvs_nacional` decimal(18,10) NOT NULL,
  `ipm` decimal(18,10) NOT NULL,
  `ranking_potencial` tinyint(4) NOT NULL,
  `ano_base_pop` smallint(6) NOT NULL DEFAULT 2025,
  `ano_base_pib` smallint(6) NOT NULL DEFAULT 2023,
  `ano_base_pdvs` smallint(6) NOT NULL DEFAULT 2021,
  `peso_pib` decimal(6,4) NOT NULL DEFAULT 0.2000,
  `peso_pop` decimal(6,4) NOT NULL DEFAULT 0.2000,
  `peso_pdvs` decimal(6,4) NOT NULL DEFAULT 0.6000,
  `atualizado_em` datetime NOT NULL,
  PRIMARY KEY (`uf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `METAS_MENSAIS` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `COD_VENDEDOR` varchar(50) NOT NULL,
  `ano` smallint(5) unsigned NOT NULL,
  `mes` tinyint(3) unsigned NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'faturamento',
  `valor_meta` decimal(15,2) NOT NULL DEFAULT 0.00,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_vendedor_ano_mes_tipo` (`COD_VENDEDOR`,`ano`,`mes`,`tipo`),
  KEY `idx_ano_mes` (`ano`,`mes`),
  KEY `idx_cod_vendedor` (`COD_VENDEDOR`)
) ENGINE=InnoDB AUTO_INCREMENT=83976 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

