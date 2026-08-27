<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupo de cliente = `GrpVendas` do TOTVS (ex.: 279 = "GRUPO CARREFOUR").
     * Mesmo desenho já usado em `segmentos`: o código bruto fica em
     * `clientes.cod_grupo` e o nome vive numa tabela de lookup, porque o TOTVS
     * só expõe a descrição em `ultimo_faturamento.Descricao` (não em CLIENTES).
     *
     * NÃO confundir com as tabelas `GRUPOS_CLIENTES`/`CLIENTES_GRUPOS` do legado:
     * aquilo é outra feature (agrupamento manual chaveado por `raiz_cnpj`, o que
     * a Regra de ouro nº 3 proíbe), tem 5 grupos e 17 vínculos, e continua fora
     * de escopo. Isto aqui é o grupo que vem do TOTVS, com 2.432 valores reais.
     */
    public function up(): void
    {
        Schema::create('grupos_cliente', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nome');
            $table->timestamps();
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('cod_grupo')->nullable()->after('cod_segmento');
            $table->index('cod_grupo');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['cod_grupo']);
            $table->dropColumn('cod_grupo');
        });

        Schema::dropIfExists('grupos_cliente');
    }
};
