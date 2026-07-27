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
        Schema::create('metas_mensais', function (Blueprint $table) {
            $table->id();
            $table->string('cod_vendedor')->index();
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->enum('tipo', ['faturamento', 'venda'])->default('faturamento');
            $table->decimal('valor_meta', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['cod_vendedor', 'ano', 'mes', 'tipo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metas_mensais');
    }
};
