<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vendedor_perfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cod_vendedor')->index();
            $table->string('cod_super')->nullable();
            $table->string('cod_gerente')->nullable();
            $table->decimal('meta_venda', 12, 2)->nullable();
            $table->decimal('meta_faturamento', 12, 2)->nullable();
            $table->string('segmento')->nullable();
            $table->string('equipe_rep')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendedor_perfis');
    }
};
