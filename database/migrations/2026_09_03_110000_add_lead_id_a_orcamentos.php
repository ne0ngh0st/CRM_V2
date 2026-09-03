<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo orçamento → lead.
 *
 * Até aqui o orçamento só COPIAVA nome e CNPJ do lead como texto (a busca de cliente do
 * formulário já sabe distinguir os dois — `buscarClientes()` devolve `origem: 'lead'` —,
 * mas o id era descartado no caminho). Duas consequências:
 *
 * 1. Não dava para responder "quantos orçamentos saíram deste lead?", que é a pergunta
 *    mais óbvia sobre um lead em negociação.
 * 2. O funil não conseguia avançar sozinho para a etapa "Orçamento" — e etapa que depende
 *    100% de disciplina humana é etapa que ninguém move.
 *
 * `nullOnDelete` porque o orçamento sobrevive ao lead: ele virou documento comercial e o
 * histórico não pode sumir junto com a origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('user_id')->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
        });
    }
};
