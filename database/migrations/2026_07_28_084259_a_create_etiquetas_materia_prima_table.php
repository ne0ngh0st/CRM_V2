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
        Schema::create('etiquetas_materia_prima', function (Blueprint $table) {
            $table->id();
            $table->string('categoria', 120)->nullable();
            $table->string('fabricante', 150)->nullable();
            $table->string('cod_mp', 80)->nullable();
            $table->string('cod_comercial', 80)->nullable();
            $table->string('desc_mp', 255);
            $table->decimal('larg_mp', 10, 2)->nullable();
            $table->decimal('preco_m2', 12, 4);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index('ativo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etiquetas_materia_prima');
    }
};
