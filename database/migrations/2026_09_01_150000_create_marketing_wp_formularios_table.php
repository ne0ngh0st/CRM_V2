<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_wp_formularios', function (Blueprint $table) {
            $table->id();
            $table->string('identificador', 64)->unique();
            $table->string('nome');
            $table->string('cod_vendedor', 20);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        DB::table('marketing_wp_formularios')->insert([
            'identificador' => '*',
            'nome' => 'Padrão (todo form sem regra própria)',
            'cod_vendedor' => '010617',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('marketing_wp_leads_raw', function (Blueprint $table) {
            $table->foreignId('formulario_id')->nullable()->after('fonte')->constrained('marketing_wp_formularios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_wp_leads_raw', function (Blueprint $table) {
            $table->dropConstrainedForeignId('formulario_id');
        });
        Schema::dropIfExists('marketing_wp_formularios');
    }
};
