<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facas', function (Blueprint $table) {
            $table->id();
            // No legado eram 8 páginas PHP quase idênticas, uma por catálogo — aqui é
            // uma coluna só, pra ter uma página com filtro em vez de 8 cópias.
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('item');
            // Largura/altura são texto porque o catálogo tem medidas não-numéricas
            // reais: "0/160" (largura móvel) e "Ø 40" (faca redonda).
            $table->string('largura', 20)->nullable();
            $table->string('altura', 20)->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->unique(['tipo', 'item']);
            $table->index('tipo');
        });

        Schema::create('faca_recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faca_id')->constrained('facas')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->string('descricao')->nullable();
            $table->string('imagem')->nullable();
            $table->timestamps();

            $table->index(['faca_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faca_recursos');
        Schema::dropIfExists('facas');
    }
};
