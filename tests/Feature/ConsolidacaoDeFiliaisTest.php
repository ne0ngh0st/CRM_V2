<?php

namespace Tests\Feature;

use App\Models\ContaEstrategica;
use App\Models\Lead;
use App\Models\Segmento;
use App\Services\Leads\ConsolidacaoDeFiliais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Um lead por empresa (`ConsolidacaoDeFiliais`, 13/10/2026): a base antiga cadastrou cada
 * loja como lead (Festval: 29) e a mesma empresa aparecia repetida.
 */
class ConsolidacaoDeFiliaisTest extends TestCase
{
    use RefreshDatabase;

    private function lead(string $nome, ?string $cnpj, array $extra = []): Lead
    {
        return Lead::forceCreate([
            'origem' => 'sistema',
            'nome' => $nome,
            'razao_social' => $nome,
            'cnpj' => $cnpj,
            'segmento' => 'SUPERMERCADISTA',
            'status' => 'ativo',
            'etapa' => 'novo',
            ...$extra,
        ]);
    }

    private function consolidar(): array
    {
        return app(ConsolidacaoDeFiliais::class)->consolidar();
    }

    public function test_fica_um_lead_por_empresa_e_a_matriz_tem_preferencia(): void
    {
        $f8 = $this->lead('FESTVAL 8', '78.116.670/0008-01', ['cod_vendedor' => '000010']);
        $matriz = $this->lead('FESTVAL MATRIZ', '78.116.670/0001-35', ['cod_vendedor' => '000010']);
        $f3 = $this->lead('FESTVAL 3', '78116670000304', ['cod_vendedor' => '000010']);
        $outra = $this->lead('OUTRA EMPRESA', '11.222.333/0001-81');

        $r = $this->consolidar();

        $this->assertSame(['empresas' => 1, 'excluidos' => 2], $r);
        $this->assertSame('ativo', $matriz->fresh()->status);
        $this->assertSame('excluido', $f8->fresh()->status);
        $this->assertSame('excluido', $f3->fresh()->status);
        $this->assertSame('ativo', $outra->fresh()->status);
    }

    public function test_historico_das_filiais_passa_para_o_lead_que_fica(): void
    {
        $matriz = $this->lead('MATRIZ', '78.116.670/0001-35');
        $filial = $this->lead('FILIAL', '78.116.670/0002-16');
        DB::table('observacoes')->insert(['lead_id' => $filial->id, 'cnpj' => '78.116.670/0002-16', 'mensagem' => 'ligar segunda', 'created_at' => now(), 'updated_at' => now()]);

        $this->consolidar();

        $this->assertSame(1, DB::table('observacoes')->where('lead_id', $matriz->id)->count());
        $this->assertSame(0, DB::table('observacoes')->where('lead_id', $filial->id)->count());
    }

    public function test_lead_ligado_a_rede_fica_mesmo_nao_sendo_a_matriz(): void
    {
        $conta = ContaEstrategica::create([
            'segmento_id' => Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA'])->id,
            'nome' => 'FESTVAL', 'ordem' => 1,
        ]);
        $matriz = $this->lead('MATRIZ', '78.116.670/0001-35');
        $ligada = $this->lead('FILIAL LIGADA', '78.116.670/0005-69', [
            'conta_estrategica_id' => $conta->id, 'conta_vinculo' => Lead::CONTA_CONFIRMADA,
        ]);

        $this->consolidar();

        $this->assertSame('ativo', $ligada->fresh()->status);
        $this->assertSame('excluido', $matriz->fresh()->status);
    }

    public function test_trabalho_do_vendedor_vence_a_base_antiga(): void
    {
        $matriz = $this->lead('MATRIZ', '78.116.670/0001-35');
        $trabalhada = $this->lead('FILIAL EM CONTATO', '78.116.670/0007-20', ['etapa' => 'em_contato']);

        $this->consolidar();

        $this->assertSame('ativo', $trabalhada->fresh()->status);
        $this->assertSame('excluido', $matriz->fresh()->status);
    }

    public function test_quem_fica_herda_dono_e_rede_que_so_a_filial_tinha(): void
    {
        $conta = ContaEstrategica::create([
            'segmento_id' => Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA'])->id,
            'nome' => 'FESTVAL', 'ordem' => 1,
        ]);
        // A matriz ganha pelo critério de origem (prospecção) e não tem dono nem rede.
        $matriz = $this->lead('MATRIZ', '78.116.670/0001-35', ['origem' => 'prospeccao']);
        $this->lead('FILIAL', '78.116.670/0002-16', [
            'cod_vendedor' => '000099', 'conta_estrategica_id' => $conta->id, 'conta_vinculo' => Lead::CONTA_SUGERIDA,
        ]);

        $this->consolidar();

        $matriz->refresh();
        $this->assertSame('000099', $matriz->cod_vendedor);
        // Sugestão não é ligação: não passa.
        $this->assertNull($matriz->conta_estrategica_id);
    }

    public function test_sem_cnpj_excluido_e_empresa_de_um_lead_so_nao_sao_tocados(): void
    {
        $semCnpj = $this->lead('SEM CNPJ', null);
        $outraSemCnpj = $this->lead('SEM CNPJ 2', '');
        $sozinho = $this->lead('SOZINHO', '11.222.333/0001-81');
        $jaExcluido = $this->lead('JA EXCLUIDO', '11.222.333/0002-62', ['status' => 'excluido']);

        $this->assertSame(['empresas' => 0, 'excluidos' => 0], $this->consolidar());
        $this->assertSame('ativo', $semCnpj->fresh()->status);
        $this->assertSame('ativo', $outraSemCnpj->fresh()->status);
        $this->assertSame('ativo', $sozinho->fresh()->status);
    }

    public function test_cnpj_sem_o_zero_do_excel_e_da_mesma_empresa(): void
    {
        $this->lead('COM ZERO', '01.234.567/0001-89');
        // 13 dígitos: o Excel comeu o zero da frente.
        $this->lead('SEM ZERO', '1234567000270');

        $this->assertSame(['empresas' => 1, 'excluidos' => 1], $this->consolidar());
    }

    public function test_rodar_de_novo_nao_faz_nada(): void
    {
        $this->lead('MATRIZ', '78.116.670/0001-35');
        $this->lead('FILIAL', '78.116.670/0002-16');

        $this->consolidar();

        $this->assertSame(['empresas' => 0, 'excluidos' => 0], $this->consolidar());
    }

    public function test_comando_dry_run_nao_mexe_em_nada(): void
    {
        $this->lead('MATRIZ', '78.116.670/0001-35');
        $filial = $this->lead('FILIAL', '78.116.670/0002-16');

        $this->artisan('leads:consolidar-filiais', ['--dry-run' => true])
            ->expectsOutputToContain('1 leads seriam excluídos')
            ->assertSuccessful();

        $this->assertSame('ativo', $filial->fresh()->status);
    }
}
