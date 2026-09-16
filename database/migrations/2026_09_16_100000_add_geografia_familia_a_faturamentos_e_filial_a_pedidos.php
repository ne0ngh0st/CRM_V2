<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O que o Power BI lê do faturamento e dos pedidos, e o import descartava.
 *
 * O relatório 198 sempre trouxe `COD_LOJA`, `Estado`, `Municipio` e `DESC_FAMILIA`, e os
 * relatórios 200/232 trazem `FILIAL` — nada disso era gravado. O BI da diretoria usa as
 * quatro primeiras (loja para casar com o cliente, estado/município para o mapa, família
 * para o corte por produto) e a filial nos dois fatos de pedido. Sem elas, trocar a fonte
 * do BI da KingHost para o RDS deixaria esses visuais vazios.
 *
 * ⚠️ `ALGORITHM=INSTANT` é EXPLÍCITO de propósito. `faturamentos` tem 5,85 M de linhas em
 * produção; coluna nullable no fim da tabela é instantânea no MySQL 8, mas, se por algum
 * motivo não puder ser (versão, formato de linha), o default do MySQL é reconstruir a
 * tabela inteira em silêncio — minutos de disco e I/O numa `db.t4g`. Declarado, o ALTER
 * recusa com erro em vez de rebuildar, e a decisão volta para quem está fazendo o deploy.
 *
 * ⚠️ As linhas antigas nascem NULL. Preencher é reimportar: `legado:import-faturamento-
 * arquivo --ano=AAAA` para 2018–2025 e o próprio 198 para 2026; para pedidos, a próxima
 * rodada do `totvs:atualizar` cobre o que está nos relatórios.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('faturamentos', 'loja')) {
            DB::statement(
                'ALTER TABLE faturamentos'
                .' ADD COLUMN loja VARCHAR(10) NULL,'
                .' ADD COLUMN estado VARCHAR(2) NULL,'
                .' ADD COLUMN municipio VARCHAR(120) NULL,'
                .' ADD COLUMN desc_familia VARCHAR(100) NULL,'
                .' ALGORITHM=INSTANT'
            );
        }

        if (! Schema::hasColumn('pedidos', 'filial')) {
            DB::statement('ALTER TABLE pedidos ADD COLUMN filial SMALLINT UNSIGNED NULL, ALGORITHM=INSTANT');
        }
    }

    public function down(): void
    {
        Schema::table('faturamentos', function ($table) {
            $table->dropColumn(['loja', 'estado', 'municipio', 'desc_familia']);
        });

        Schema::table('pedidos', function ($table) {
            $table->dropColumn('filial');
        });
    }
};
