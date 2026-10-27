<?php

namespace Tests\Feature\Faturamento;

use App\Models\Produto;
use App\Services\VisaoDiretor\FaturamentoMensalRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VolumePorFamiliaRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_caixa_de_bobina_sai_do_tamanho_escrito_no_codigo(): void
    {
        $mes = CarbonImmutable::today()->startOfMonth();
        $this->produto('B-CX', 'BOBINA', 'CX', 'BOBINA TERMICO 80X40M AUTOPEL');
        $this->produto('B-CX30', 'BOBINA', 'CX', 'BOBINA TERMICO 80X40M ESTAPAR SC C/30');
        $this->produto('B-UN30', 'BOBINA', 'UN', 'BOBINA TS/COTEPE 80X40M SUP BOA CX C/30');
        $this->produto('B-UN', 'BOBINA', 'UN', 'BOBINA TS KPH BC 80X40M AUTOPEL');
        $this->produto('B-RL', 'BOBINA', 'RL', 'BOBINA SEM TAMANHO');
        $this->produto('E-RL', 'ETIQUETA', 'RL', 'ETIQUETA');

        $this->nota('B-CX', 10, 100);
        $this->nota('B-CX30', 2, 20);
        $this->nota('B-UN30', 30, 30);
        $this->nota('B-UN', 90, 90);
        $this->nota('B-RL', 7, 70);
        $this->nota('E-RL', 3, 30);

        app(FaturamentoMensalRollup::class)->recalcular($mes, $mes);

        $bobina = DB::table('faturamento_cliente_familia_mensal')
            ->where('mes', $mes->toDateString())
            ->where('familia', 'bobina')
            ->first();
        $etiqueta = DB::table('faturamento_cliente_familia_mensal')
            ->where('familia', 'etiqueta')
            ->value('quantidade');

        // 10 caixas (já CX) + 2 caixas (CX com C/30 não se divide) + 30 unidades ÷ 30 = 1.
        $this->assertEqualsWithDelta(13, (float) $bobina->quantidade, 0.001);
        // 90 + 7 ficam de fora: o nome não diz quantas unidades cabem na caixa.
        $this->assertEqualsWithDelta(97, (float) $bobina->unidades_sem_caixa, 0.001);
        $this->assertEquals(3, (float) $etiqueta);
        $this->assertEqualsWithDelta(340, (float) DB::table('faturamento_cliente_mensal')->sum('valor_total'), 0.001);

        app(FaturamentoMensalRollup::class)->recalcular($mes, $mes);
        $this->assertSame(2, DB::table('faturamento_cliente_familia_mensal')->count());
    }

    private function produto(string $codigo, string $categoria, string $unidade, string $descricao): void
    {
        Produto::create([
            'cod_produto' => $codigo,
            'descricao' => $descricao,
            'categoria' => $categoria,
            'unidade' => $unidade,
        ]);
    }

    private function nota(string $produto, float $quantidade, float $valor): void
    {
        DB::table('faturamentos')->insert([
            'cod_cliente' => '000123',
            'cod_vendedor' => '000010',
            'cod_produto' => $produto,
            'data_emissao' => CarbonImmutable::today()->startOfMonth()->addDays(2)->toDateString(),
            'quantidade' => $quantidade,
            'valor_total' => $valor,
            'nota_fiscal' => $produto,
        ]);
    }
}
