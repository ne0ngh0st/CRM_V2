<?php

namespace Tests\Feature\Ia;

use App\Models\GrupoCliente;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * "Grupo 000258" é GrpVendas 258, não o cliente 000258.
 */
class ConsultarGrupoTest extends IaTestCase
{
    public function test_grupo_000258_nao_e_o_cliente_de_mesmo_numero_e_nao_soma_as_caixas_dele(): void
    {
        GrupoCliente::create(['codigo' => '258', 'nome' => 'HARD PARK']);
        $this->cliente([
            'cod_cliente' => '000258',
            'cnpj' => '11.111.111/0001-11',
            'razao_social' => 'PSI TECNOLOGIA LTDA',
            'cod_grupo' => '1',
        ]);
        $this->cliente([
            'cod_cliente' => '215371',
            'loja' => '0001',
            'cnpj' => '63.459.596/0001-02',
            'razao_social' => 'HARD PARK ESTACIONAMENTO LTDA',
            'cod_vendedor' => '000115',
            'cod_grupo' => '258',
        ]);
        $this->caixas('215371', 40);
        $this->caixas('000258', 999);

        $this->roteiro([R::ferramenta('consultar_grupo', [
            'codigo' => '000258', 'nome' => null, 'familia' => 'bobina',
        ]), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'quantas caixas o grupo compra')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame('258', $r['codigo']);
        $this->assertSame('HARD PARK', $r['nome']);
        $this->assertStringContainsString('PSI TECNOLOGIA', $r['aviso_codigo_de_cliente']);
        $json = json_encode($r);
        $this->assertStringContainsString('40', $json);
        $this->assertStringNotContainsString('999', $json);
    }

    public function test_buscar_cliente_avisa_que_o_numero_e_um_grupo(): void
    {
        GrupoCliente::create(['codigo' => '258', 'nome' => 'HARD PARK']);
        $this->cliente([
            'cod_cliente' => '000258',
            'cnpj' => '11.111.111/0001-11',
            'razao_social' => 'PSI TECNOLOGIA LTDA',
            'cod_grupo' => '1',
        ]);

        $this->roteiro([R::ferramenta('buscar_cliente', ['termo' => '000258']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'grupo 000258')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame('PSI TECNOLOGIA LTDA', $r['clientes'][0]['razao_social']);
        $this->assertSame('258', $r['grupo_com_este_numero']['codigo']);
        $this->assertSame('HARD PARK', $r['grupo_com_este_numero']['nome']);
        $this->assertStringContainsString('consultar_grupo', $r['grupo_com_este_numero']['aviso']);
    }

    public function test_vendedor_nao_ve_as_caixas_da_empresa_do_colega(): void
    {
        GrupoCliente::create(['codigo' => '258', 'nome' => 'HARD PARK']);
        $this->cliente([
            'cod_cliente' => '215371',
            'loja' => '0001',
            'cnpj' => '63.459.596/0001-02',
            'razao_social' => 'HARD PARK ESTACIONAMENTO LTDA',
            'cod_vendedor' => '000115',
            'cod_grupo' => '258',
        ]);
        $this->cliente([
            'cod_cliente' => '300000',
            'cnpj' => '22.222.222/0001-22',
            'razao_social' => 'FILIAL DO COLEGA',
            'cod_vendedor' => '000020',
            'cod_grupo' => '258',
        ]);
        $this->caixas('215371', 40);
        $this->caixas('300000', 90);

        $this->roteiro([R::ferramenta('consultar_grupo', [
            'codigo' => '258', 'nome' => null, 'familia' => 'bobina',
        ]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000115'), 'caixas do grupo')->assertOk();

        $json = json_encode($this->resultadoDaFerramenta());
        $this->assertStringContainsString('40', $json);
        $this->assertStringNotContainsString('90', $json);
        $this->assertStringNotContainsString('FILIAL DO COLEGA', $json);
    }

    public function test_vendedor_sem_empresa_no_grupo_nao_descobre_o_nome(): void
    {
        GrupoCliente::create(['codigo' => '258', 'nome' => 'HARD PARK']);
        $this->cliente([
            'cod_cliente' => '215371',
            'cnpj' => '63.459.596/0001-02',
            'razao_social' => 'HARD PARK ESTACIONAMENTO LTDA',
            'cod_vendedor' => '000115',
            'cod_grupo' => '258',
        ]);

        $this->roteiro([R::ferramenta('consultar_grupo', [
            'codigo' => '000258', 'nome' => null, 'familia' => 'bobina',
        ]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'caixas do grupo')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertFalse($r['encontrado']);
        $this->assertStringNotContainsString('HARD PARK', json_encode($r));
    }

    public function test_clientes_diversos_nao_tem_volume(): void
    {
        GrupoCliente::create(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);

        $this->roteiro([R::ferramenta('consultar_grupo', [
            'codigo' => '9998', 'nome' => null, 'familia' => 'bobina',
        ]), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'caixas do grupo 9998')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertNull($r['caixas_por_mes']);
        $this->assertStringContainsString('DIVERSOS', $r['mensagem']);
    }

    private function caixas(string $codCliente, float $quantidade): void
    {
        DB::table('faturamento_cliente_familia_mensal')->insert([
            'cod_cliente' => $codCliente,
            'mes' => now()->startOfMonth()->toDateString(),
            'familia' => 'bobina',
            'quantidade' => $quantidade,
            'calculado_em' => now(),
        ]);
    }
}
