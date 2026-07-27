<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->enum('origem', ['sistema', 'manual'])->default('sistema');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cod_vendedor')->nullable()->index();
            $table->string('nome');
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('cnpj', 18)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('telefone', 30)->nullable();
            $table->string('endereco')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado', 2)->nullable()->index();
            $table->string('segmento')->nullable()->index();
            $table->decimal('valor_estimado', 12, 2)->nullable();
            $table->enum('status', ['ativo', 'inativo', 'convertido', 'excluido'])->default('ativo')->index();
            $table->timestamps();

            $table->index(['origem', 'status']);
            $table->index(['cod_vendedor', 'status']);
        });

        if (Schema::hasTable('leads_manuais')) {
            $rows = DB::table('leads_manuais')->get();
            foreach ($rows as $row) {
                DB::table('leads')->insert([
                    'origem' => 'manual',
                    'user_id' => $row->user_id,
                    'cod_vendedor' => $row->cod_vendedor,
                    'nome' => $row->nome ?: $row->razao_social,
                    'razao_social' => $row->razao_social,
                    'nome_fantasia' => $row->nome_fantasia,
                    'cnpj' => $row->cnpj,
                    'email' => $row->email,
                    'telefone' => $row->telefone,
                    'endereco' => $row->endereco,
                    'cidade' => null,
                    'estado' => null,
                    'segmento' => null,
                    'valor_estimado' => null,
                    'status' => in_array($row->status, ['ativo', 'inativo', 'convertido', 'excluido'], true)
                        ? $row->status
                        : 'ativo',
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }

        Schema::table('ligacoes', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('cliente_id')->constrained('leads')->nullOnDelete();
            $table->index(['lead_id', 'data_ligacao']);
        });

        Schema::table('observacoes', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('cliente_id')->constrained('leads')->nullOnDelete();
            $table->index('lead_id');
        });

        // cliente_id passa a ser opcional (agendamento pode ser de lead).
        Schema::table('agendamentos_ligacoes', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
        });

        DB::statement('ALTER TABLE agendamentos_ligacoes MODIFY cliente_id BIGINT UNSIGNED NULL');

        Schema::table('agendamentos_ligacoes', function (Blueprint $table) {
            $table->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('cliente_id')->constrained('leads')->nullOnDelete();
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('agendamentos_ligacoes', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id']);
            $table->dropColumn('lead_id');
            $table->dropForeign(['cliente_id']);
        });

        DB::statement('ALTER TABLE agendamentos_ligacoes MODIFY cliente_id BIGINT UNSIGNED NOT NULL');

        Schema::table('agendamentos_ligacoes', function (Blueprint $table) {
            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
        });

        Schema::table('observacoes', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id']);
            $table->dropColumn('lead_id');
        });

        Schema::table('ligacoes', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropIndex(['lead_id', 'data_ligacao']);
            $table->dropColumn('lead_id');
        });

        Schema::dropIfExists('leads');
    }
};
