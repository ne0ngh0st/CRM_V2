<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de peso segmento × família de produto, usada pelo Potencial da Carteira.
 *
 * O peso responde "esta família entra na cesta típica deste segmento, e com que
 * prioridade?" — supermercado compra bobina e etiqueta; posto de gasolina, muito menos.
 * Peso 0 tira o segmento dos candidatos daquela família.
 *
 * ⚠️ Nasce com TODOS os pesos em 1,00 (o `PotencialPesoSeeder`): a matriz real vem da
 * direção e ainda não existe. Enquanto for tudo 1, o card declara isso na tela em vez de
 * deixar o vendedor ler como prioridade o que hoje é contagem bruta.
 *
 * ⚠️ Tabela, e não `config/`, pelo mesmo motivo que `segmentos` virou tabela em julho:
 * é dado de negócio que muda sem deploy, e uma tela de edição depois não exige refazer
 * nada. O legado guardava o equivalente disto num CSV (`segmentos_2026_curva_abc.csv`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potencial_pesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segmento_id')->constrained('segmentos')->cascadeOnDelete();
            // String e não enum: família nova não deve exigir ALTER TABLE. A lista válida
            // mora em App\Services\Potencial\FamiliaProduto, que estoura no valor errado.
            $table->string('familia', 20);
            $table->decimal('peso', 5, 2)->default(1);
            $table->timestamps();

            $table->unique(['segmento_id', 'familia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potencial_pesos');
    }
};
