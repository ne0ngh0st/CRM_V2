<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço do cliente no orçamento — sugestão do Vagner Sabelli (10/09/2026),
 * aprovada: "Constar no orçamento o endereço completo correspondente ao cnpj orçado".
 *
 * ⚠️ O dado NÃO é novo e não precisou ser pedido ao Adriano: `Clientes - SQL.csv`
 * (o relatório 210, que o `totvs:atualizar` já baixa de hora em hora) traz as colunas
 * `Endereco` e `Municipio` desde sempre, preenchidas em 92.163 de 92.163 clientes —
 * o importador é que as descartava na porta de entrada.
 *
 * ⚠️ NÃO existe bairro na origem. O TOTVS manda logradouro e número juntos num campo
 * só ("RUA NOVE 420", "AVENIDA ORLANDO JERONIMO TELES, 87") e o município à parte;
 * `estado` e `cep` a `clientes` já tinha. Por isso o endereço do documento sai como
 * "logradouro, número — município/UF — CEP", e não há campo de bairro a preencher.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUE O ORÇAMENTO GUARDA CÓPIA EM VEZ DE LER DE `clientes`
 *
 * Orçamento é DOCUMENTO: o que foi impresso e enviado ao cliente não pode mudar
 * depois. A tabela já seguia isso com `cliente_nome`, `cliente_cnpj` e
 * `cliente_contato`, que são cópias e não joins — as quatro colunas daqui entram no
 * mesmo regime. Ler de `clientes` faria o PDF de um orçamento de março reimprimir
 * hoje com o endereço novo da empresa, sem nenhum aviso.
 *
 * Há ainda dois casos que um join nem atenderia: orçamento para LEAD (que não está
 * em `clientes`) e orçamento digitado à mão, sem vínculo nenhum.
 *
 * ⚠️ Sem backfill, de propósito, e aqui a razão é diferente da do `preco_tabela`
 * (2026-09-03): lá o valor existia e reescrevê-lo mudaria o nível de aprovação de
 * documentos já aprovados. Aqui o dado nunca existiu, então não há o que preservar —
 * mas carimbar nos 2.127 orçamentos históricos o endereço de HOJE afirmaria que era
 * esse o endereço na data de emissão, o que ninguém verificou. Em vez disso o
 * documento antigo cai para o endereço atual do cliente na hora de exibir, e essa
 * decisão mora em `Orcamento::enderecoDoDocumento()` — um lugar só.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Espelho puro do TOTVS, como o resto de `clientes` (Regra de ouro nº 4):
            // o CRM nunca escreve aqui, só o import.
            $table->string('endereco')->nullable()->after('nome_fantasia');
            $table->string('municipio', 120)->nullable()->after('endereco');
        });

        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('cliente_endereco')->nullable()->after('cliente_cnpj');
            $table->string('cliente_municipio', 120)->nullable()->after('cliente_endereco');
            $table->string('cliente_estado', 2)->nullable()->after('cliente_municipio');
            $table->string('cliente_cep', 10)->nullable()->after('cliente_estado');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['endereco', 'municipio']);
        });

        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn(['cliente_endereco', 'cliente_municipio', 'cliente_estado', 'cliente_cep']);
        });
    }
};
