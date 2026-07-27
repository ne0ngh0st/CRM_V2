<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->string('cod_produto');
            $table->string('descricao');
            $table->string('categoria')->nullable();
            $table->string('unidade', 10)->nullable();
            $table->decimal('preco_tabela', 12, 4)->nullable();
            $table->timestamps();

            $table->unique('cod_produto');
            $table->index('categoria');
            $table->index('descricao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
