<?php

use App\Services\PowerBi\SchemaBi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tabelas de referência do Power BI, no schema `bi`: IBGE, de-para de município,
 * indicadores por município e potencial por estado.
 *
 * Vieram do `autopel01`, que sai do ar até 31/10/2026, e não existem no TOTVS. O
 * conteúdo está versionado em `database/dados-bi/` e entra por `bi:carregar-referencias`
 * — a migration só cria a estrutura.
 *
 * ⚠️ O SCHEMA JÁ PRECISA EXISTIR. Ele é criado com a credencial master
 * (`infra/bi/criar-schema-e-usuario.sh`), porque o usuário do app não tem CREATE global.
 * Sem ele, esta migration para com a instrução do que fazer.
 *
 * ⚠️ `DROP TABLE IF EXISTS` antes de criar, de propósito. O `migrate:fresh` só apaga o
 * banco padrão, não o `bi`; sem o drop, a segunda execução da suíte (ou um `migrate:fresh`
 * no dev) morreria em "table already exists". Recriar vazio é seguro porque o conteúdo
 * inteiro vem dos CSVs versionados — basta recarregar.
 *
 * ⚠️ Collation `utf8mb4_unicode_ci`, a MESMA das tabelas do app. As views juntam
 * `clientes.municipio` com `de_para_municipio.nome_norm` entre schemas; com collations
 * diferentes o MySQL recusa ("Illegal mix of collations"). E ela é insensível a acento,
 * que é o que faz 'SAO PAULO' casar com 'SÃO PAULO' — o legado dependia disso também.
 *
 * Diferenças para o legado: a `IBGE_MUNICIPIOS` perdeu o `id` sequencial (o código IBGE
 * já é a chave) e os nomes ficaram em minúsculas; as colunas não mudaram.
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaBi::exigir();

        $opcoes = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        foreach ($this->tabelas() as $tabela => $colunas) {
            DB::statement('DROP TABLE IF EXISTS '.SchemaBi::tabela($tabela));
            DB::statement('CREATE TABLE '.SchemaBi::tabela($tabela)." ({$colunas}) {$opcoes}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tabelas()) as $tabela) {
            DB::statement('DROP TABLE IF EXISTS '.SchemaBi::tabela($tabela));
        }
    }

    /** @return array<string, string> */
    private function tabelas(): array
    {
        return [
            // ⚠️ Micro e mesorregião são opcionais: Boa Esperança do Norte/MT (criado em
            // 2025) não tem nenhuma das duas. O legado guardava nome vazio e código 0; o
            // código 0 foi mantido como veio, o nome vazio vira NULL.
            'ibge_municipios' => '
                cod_municipio INT NOT NULL PRIMARY KEY,
                nome_municipio VARCHAR(200) NOT NULL,
                cod_micro INT NOT NULL,
                nome_micro VARCHAR(200) NULL,
                cod_meso INT NOT NULL,
                nome_meso VARCHAR(200) NULL,
                cod_uf INT NOT NULL,
                cod_regiao INT NULL,
                nome_regiao VARCHAR(50) NULL,
                sigla_uf CHAR(2) NOT NULL,
                KEY ibge_uf_idx (sigla_uf)',

            // Origem: IBGE | GRAFIA_ANTIGA | ERRO_DIGITACAO | REGIAO_ADM
            'de_para_municipio' => '
                uf CHAR(2) NOT NULL,
                nome_norm VARCHAR(120) NOT NULL,
                cod_municipio INT NOT NULL,
                origem VARCHAR(20) NOT NULL,
                PRIMARY KEY (uf, nome_norm)',

            'indicadores_municipio' => '
                cod_municipio INT NOT NULL PRIMARY KEY,
                pea_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                ano_base_pea SMALLINT NOT NULL,
                populacao DECIMAL(15,2) NOT NULL DEFAULT 0,
                ano_base_pop SMALLINT NOT NULL,
                pib_total_reais DECIMAL(18,2) NOT NULL DEFAULT 0,
                ano_base_pib SMALLINT NOT NULL,
                num_supermercados INT NOT NULL DEFAULT 0,
                supermercados_pessoal_ocupado INT NOT NULL DEFAULT 0,
                supermercados_massa_salarial_reais DECIMAL(18,2) NOT NULL DEFAULT 0,
                ano_base_supermercados SMALLINT NULL,
                atualizado_em DATETIME NOT NULL',

            'potencial_mercado_estado' => '
                uf CHAR(2) NOT NULL PRIMARY KEY,
                estado VARCHAR(60) NOT NULL,
                regiao VARCHAR(20) NOT NULL,
                populacao BIGINT NOT NULL,
                pib_milhoes DECIMAL(18,2) NOT NULL,
                pib_per_capita DECIMAL(18,6) NOT NULL,
                pct_pib_nacional DECIMAL(18,10) NOT NULL,
                pct_populacao_nacional DECIMAL(18,10) NOT NULL,
                num_pdvs INT NOT NULL,
                pct_pdvs_nacional DECIMAL(18,10) NOT NULL,
                ipm DECIMAL(18,10) NOT NULL,
                ranking_potencial TINYINT NOT NULL,
                ano_base_pop SMALLINT NOT NULL,
                ano_base_pib SMALLINT NOT NULL,
                ano_base_pdvs SMALLINT NOT NULL,
                peso_pib DECIMAL(6,4) NOT NULL,
                peso_pop DECIMAL(6,4) NOT NULL,
                peso_pdvs DECIMAL(6,4) NOT NULL,
                atualizado_em DATETIME NOT NULL',
        ];
    }
};
