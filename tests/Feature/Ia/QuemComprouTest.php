<?php

namespace Tests\Feature\Ia;

use App\Models\Produto;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * "Já vendemos rótulo? Quem?" sai da nota, pelo nome do produto, no escopo de quem pergunta.
 */
class QuemComprouTest extends IaTestCase
{
    public function test_acha_quem_faturou_e_esconde_o_cliente_de_outra_carteira(): void
    {
        $this->cliente([
            'cod_cliente' => '016256',
            'loja' => '0001',
            'cnpj' => '02.916.265/0001-60',
            'razao_social' => 'JBS S/A',
            'cod_vendedor' => '000010',
        ]);
        $this->cliente([
            'cod_cliente' => '171179',
            'loja' => '0001',
            'cnpj' => '15.436.940/0001-03',
            'razao_social' => 'AMAZON TRANSPORTES LTDA',
            'cod_vendedor' => '000020',
        ]);
        $this->nota('V60026', '016256', 'ROTULO BOPP FOSCO 79X120X188M FRIBOI', '2024-06-28', '2024-11-01');
        $this->nota('V60026', '171179', 'ROTULO BOPP FOSCO 79X120X188M FRIBOI', '2025-11-06', '2025-11-06');
        Produto::create([
            'cod_produto' => 'V60025',
            'descricao' => 'IN MOLD LABEL COM 100 ROTULOS PARA DEMONSTRACAO',
            'categoria' => 'ETIQUETA',
            'unidade' => 'UN',
        ]);

        $this->roteiro([R::ferramenta('quem_comprou', ['termo' => 'rótulo']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'já vendemos rótulo pra alguém?')->assertOk();

        $json = json_encode($this->resultadoDaFerramenta());
        $this->assertStringContainsString('JBS', $json);
        $this->assertStringContainsString('V60026', $json);
        $this->assertStringContainsString('V60025', $json);
        $this->assertStringNotContainsString('AMAZON', $json);
    }

    public function test_admin_ve_as_duas_empresas(): void
    {
        $this->cliente([
            'cod_cliente' => '016256',
            'cnpj' => '02.916.265/0001-60',
            'razao_social' => 'JBS S/A',
            'cod_vendedor' => '000010',
        ]);
        $this->cliente([
            'cod_cliente' => '171179',
            'loja' => '0002',
            'cnpj' => '15.436.940/0001-03',
            'razao_social' => 'AMAZON TRANSPORTES LTDA',
            'cod_vendedor' => '000020',
        ]);
        $this->nota('V60026', '016256', 'ROTULO BOPP FOSCO FRIBOI', '2024-06-28', '2024-11-01');
        $this->nota('V60026', '171179', 'ROTULO BOPP FOSCO FRIBOI', '2025-11-06', '2025-11-06');

        $this->roteiro([R::ferramenta('quem_comprou', ['termo' => 'rotulo']), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'quem comprou rotulo')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame(2, $r['total_empresas']);
        $this->assertSame('AMAZON TRANSPORTES LTDA', $r['clientes'][0]['razao_social']);
    }

    private function nota(string $produto, string $cliente, string $descricao, string $de, string $ate): void
    {
        DB::table('faturamento_cliente_produto')->insert([
            'cod_produto' => $produto,
            'cod_cliente' => $cliente,
            'descricao' => $descricao,
            'primeira_emissao' => $de,
            'ultima_emissao' => $ate,
        ]);
    }
}
