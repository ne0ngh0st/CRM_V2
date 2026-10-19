<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Services\VisaoDiretor\FaturamentoMensalRollup;
use App\Services\VisaoDiretor\Redes\VinculoDeRedes;
use App\Services\VisaoDiretor\VinculoPorProduto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vínculo por PRODUTO PERSONALIZADO (Tony, 2026-10-19): quem compra a bobina com o nome da
 * rede é cliente da rede, em todas as categorias da página Maiores redes.
 */
class VinculoPorProdutoTest extends TestCase
{
    use RefreshDatabase;

    private int $loja = 0;

    /** Mutação: tirar o `valor_total > 0` (a devolução entra), ou o LEAST/GREATEST. */
    public function test_rollup_guarda_quem_comprou_qual_produto(): void
    {
        $this->nota('2026-08-05', '003781', 'V23470', 'BOBINA MAX MUFFATO', 120);
        $this->nota('2026-09-20', '003781', 'V23470', 'BOBINA MAX MUFFATO', 80);
        $this->nota('2026-09-21', '000500', 'V23470', 'BOBINA MAX MUFFATO', -80);

        app(FaturamentoMensalRollup::class)->recalcular(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
        app(FaturamentoMensalRollup::class)->recalcular(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        $linhas = DB::table('faturamento_cliente_produto')->get();
        $this->assertCount(1, $linhas, 'devolução não conta como compra');
        $this->assertSame('003781', $linhas[0]->cod_cliente);
        $this->assertSame('2026-08-05', $linhas[0]->primeira_emissao);
        $this->assertSame('2026-09-20', $linhas[0]->ultima_emissao, 'recalcular um mês antigo não apaga a compra mais nova');
    }

    public function test_marca_casa_palavra_inteira_e_nunca_parte_dela(): void
    {
        $this->filial('003781', '101', 'COMERCIAL PR');
        $this->filial('000600', '101', 'OUTRO MERCADO');
        $this->compra('003781', 'V1', 'BOBINA TS KPH BC 80X80M MAX MUFFATO');
        $this->compra('000600', 'V2', 'BOBINA MUFFATOS DO BRASIL');

        $r = $this->casar([['termos' => ['SUPER MUFFATO', 'MUFFATO'], 'segmento' => '101']]);

        $this->assertSame(['003781'], array_map('strval', array_keys($r['clientes'])));
    }

    /** Mutação: tirar o corte das palavras de tipo nas pontas ("SUPER", "IRMÃOS"). */
    public function test_palavra_de_tipo_nas_pontas_sai_do_nome_da_rede(): void
    {
        $this->filial('003781', '101', 'COMERCIAL PR');
        $this->compra('003781', 'V1', 'ETIQUETA MUFFATO 40X40');

        $this->assertArrayHasKey('003781', $this->casar([['termos' => ['IRMÃOS MUFFATO S.A'], 'segmento' => '101']])['clientes']);
    }

    /** Mutação: tirar `MAXIMO_COMPRADORES` ou a trava de segmento. */
    public function test_produto_de_prateleira_e_comprador_de_outro_segmento_nao_ligam(): void
    {
        for ($i = 0; $i <= VinculoPorProduto::MAXIMO_COMPRADORES; $i++) {
            $codigo = str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT);
            DB::table('faturamento_cliente_produto')->insert($this->par('P1', $codigo, 'BOBINA PANVEL 57X30'));
        }
        $this->filial('000700', '108', 'LOJA QUALQUER');
        $this->compra('000700', 'P2', 'ETIQUETA RAIADROGASIL PERSONALIZADA');

        $r = $this->casar([
            ['termos' => ['PANVEL'], 'segmento' => '109'],
            ['termos' => ['RAIADROGASIL'], 'segmento' => '109'],
        ]);

        $this->assertSame([], $r['clientes']);
    }

    /** Mutação: tirar a detecção de revenda. */
    public function test_quem_compra_produto_de_duas_redes_e_revenda(): void
    {
        $this->filial('000800', '101', 'GRAFICA RAPIDA');
        $this->compra('000800', 'V1', 'BOBINA MAX MUFFATO');
        $this->compra('000800', 'V2', 'BOBINA SAVEGNAGO 80X40');

        $r = $this->casar([
            ['termos' => ['SUPER MUFFATO'], 'segmento' => '101'],
            ['termos' => ['SAVEGNAGO'], 'segmento' => '101'],
        ]);

        $this->assertSame([], $r['clientes']);
        $this->assertArrayHasKey('000800', $r['revendas']);
    }

    /** Página Maiores redes: entra com o nível `produto`; o nome continua valendo mais. */
    public function test_maiores_redes_liga_pelo_produto(): void
    {
        $this->filial('003781', '101', 'COMERCIAL PR');
        $this->filial('000900', '101', 'SUPER MUFFATO CENTRO');
        $this->compra('003781', 'V1', 'BOBINA MAX MUFFATO');
        $this->compra('000900', 'V1', 'BOBINA MAX MUFFATO');

        $r = app(VinculoDeRedes::class)->calcular([
            ['nome' => 'SUPER MUFFATO', 'apelidos' => ['MUFFATO'], 'categoria' => 'supermercados', 'uf' => 'PR', 'lojas' => 121],
        ]);

        $this->assertSame(['rede' => 0, 'nivel' => VinculoDeRedes::PRODUTO], $r['clientes']['003781']);
        $this->assertSame(['rede' => 0, 'nivel' => VinculoDeRedes::NOME], $r['clientes']['000900']);
    }

    /** Mutação: tirar `nomeComum()` do `casar()`. "LIVRO PONTO" ligava gráfica ao Ponto Frio. */
    public function test_marca_de_nome_comum_na_base_nao_liga(): void
    {
        for ($i = 0; $i <= VinculoPorProduto::MAXIMO_EMPRESAS_COM_O_NOME; $i++) {
            $this->filial((string) (200000 + $i), '101', 'MERCADO TROPICAL '.$i);
        }
        $this->filial('000700', '101', 'COMERCIAL PR');
        $this->compra('000700', 'P1', 'BOBINA TS KPH BC 80X40M TROPICAL');

        $this->assertSame([], $this->casar([['termos' => ['TROPICAL SUPERMERCADOS'], 'segmento' => '101']])['clientes']);
    }

    /** Mutação: tirar o `isset($candidatos[$codigo])` do laço de produto em `VinculoDeRedes`. */
    public function test_produto_nao_tira_quem_o_nome_ja_ligou(): void
    {
        $this->filial('000900', '101', 'SAVEGNAGO CENTRO');
        $this->compra('000900', 'V1', 'BOBINA MAX MUFFATO');

        $r = app(VinculoDeRedes::class)->calcular([
            ['nome' => 'SUPER MUFFATO', 'apelidos' => ['MUFFATO'], 'categoria' => 'supermercados', 'uf' => 'PR', 'lojas' => 121],
            ['nome' => 'SAVEGNAGO', 'apelidos' => [], 'categoria' => 'supermercados', 'uf' => 'SP', 'lojas' => 60],
        ]);

        $this->assertSame(['rede' => 1, 'nivel' => VinculoDeRedes::NOME], $r['clientes']['000900']);
        $this->assertSame([], $r['conflitos']);
    }

    // ---------------------------------------------------------------- apoio

    private function casar(array $alvos): array
    {
        return app(VinculoPorProduto::class)->casar($alvos);
    }

    private function nota(string $data, string $cliente, string $produto, string $descricao, float $valor): void
    {
        DB::table('faturamentos')->insert([
            'data_emissao' => $data, 'cod_cliente' => $cliente, 'cod_vendedor' => '000001', 'nota_fiscal' => uniqid(),
            'cod_produto' => $produto, 'produto_desc' => $descricao, 'valor_total' => $valor,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function compra(string $cliente, string $produto, string $descricao): void
    {
        DB::table('faturamento_cliente_produto')->insert($this->par($produto, $cliente, $descricao));
    }

    private function par(string $produto, string $cliente, string $descricao): array
    {
        return ['cod_produto' => $produto, 'cod_cliente' => $cliente, 'descricao' => $descricao, 'primeira_emissao' => '2025-01-10', 'ultima_emissao' => '2025-06-10'];
    }

    private function filial(string $codigo, string $segmento, string $fantasia): void
    {
        $this->loja++;
        Cliente::create([
            'cod_cliente' => $codigo, 'loja' => str_pad((string) $this->loja, 4, '0', STR_PAD_LEFT),
            'razao_social' => 'EMPRESA '.$codigo.' LTDA', 'nome_fantasia' => $fantasia,
            'cod_grupo' => '9998', 'cod_segmento' => $segmento, 'cod_vendedor' => '000001', 'estado' => 'PR',
        ]);
    }
}
