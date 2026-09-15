<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lookup código -> nome do vendedor, do TOTVS. Mesmo desenho de `segmentos` e
     * `grupos_cliente`: o código bruto mora em `clientes.cod_vendedor` e a descrição
     * vive numa tabela à parte, porque o TOTVS só expõe o nome do vendedor no relatório
     * `199 - ULTIMO FATURAMENTO` (colunas `Codigo`/`Nome`/`Nome Reduzid`), nunca no
     * cadastro de clientes.
     *
     * ⚠️ NÃO confundir com `vendedor_perfis`, e as duas não são substituíveis:
     *
     *   `vendedor_perfis` → o vendedor que TEM CONTA no CRM (1:1 com `users`).
     *   `vendedores_totvs` → todo código que o TOTVS usa, inclusive quem não tem conta.
     *
     * A segunda existe porque a primeira não cobre a base: 320 dos 444 códigos de
     * `clientes.cod_vendedor` não têm perfil no CRM — ex-funcionários, gente de
     * licitação/SAC (fora de escopo pela Regra de ouro nº 2) e os baldes do próprio
     * TOTVS ("INATIVOS", "CLIENTE SEM COMPRA"). São 25.208 clientes, 27% da base, que
     * até aqui exibiam o código cru ("010148") no lugar do nome na Carteira, nos
     * Pedidos, nos Leads e nos Excel — porque o único fallback era o próprio código.
     *
     * Populada dentro do `totvs:import-clientes` / `legado:import-clientes`, no mesmo
     * passo que lê o último faturamento — pelo mesmo motivo que grupo e segmento: passo
     * separado deixaria importar cliente com código que ainda não existe no lookup, e a
     * tela voltaria a mostrar o código sem ninguém ligar uma coisa à outra.
     */
    public function up(): void
    {
        Schema::create('vendedores_totvs', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nome');
            // O TOTVS corta o reduzido em 25 caracteres, no meio da palavra
            // ("FABIA RENATA BRYAN MARITA"). Guardado porque é o que o relatório traz
            // e distingue homônimos ("RICARDO CAMPOS - TLMK"), mas quem a tela exibe é
            // o `nome`.
            $table->string('nome_reduzido')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendedores_totvs');
    }
};
