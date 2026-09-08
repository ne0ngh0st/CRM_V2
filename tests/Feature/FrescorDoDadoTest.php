<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totvs\FrescorDoDado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A pill "Dados:" do Painel.
 *
 * ⚠️ O caso que dá nome a este arquivo é `test_pill_reflete_importacao_recente`: até
 * 2026-09-08 a pill lia `data_sync_status`, escrita SÓ pelo seeder, e continuou dizendo
 * "Desatualizado" logo depois de uma importação bem-sucedida em produção. Um teste que
 * apenas verificasse "a pill aparece" passaria com o defeito presente — por isso todo caso
 * aqui parte do DADO (a nota, o pedido) e nunca de uma tabela de marcação.
 */
class FrescorDoDadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'vendedor', 'representante', 'supervisor', 'assistente', 'diretor'] as $papel) {
            Role::findOrCreate($papel);
        }
    }

    /**
     * Inserção crua de propósito: é assim que os `totvs:import-*` escrevem, e o frescor é
     * medido justamente sobre o que eles gravam.
     */
    private function nota(string $data): void
    {
        DB::table('faturamentos')->insert([
            'data_emissao' => $data,
            'cod_vendedor' => '000123',
            'valor_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pedido(string $data): void
    {
        DB::table('pedidos')->insert([
            'numero_pedido' => 'P'.uniqid(),
            'cod_vendedor' => '000123',
            'data_pedido' => $data,
            'valor_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pill_reflete_importacao_recente(): void
    {
        // Quinta-feira. A nota é de ontem: é o que uma importação saudável produz.
        Carbon::setTestNow('2026-09-10 09:00:00');

        $this->nota('2026-09-09');
        $this->pedido('2026-09-10');

        $pior = app(FrescorDoDado::class)->pior();

        $this->assertSame('atualizado', $pior['status']);
        $this->assertSame('Faturamento', $pior['dominio'], 'o pior domínio é o mais velho');
        $this->assertSame(1, $pior['dias']);
    }

    public function test_dado_parado_ha_um_mes_fica_vermelho(): void
    {
        // O caso real de produção: um mês sem relatório novo, alarmes todos verdes.
        Carbon::setTestNow('2026-09-08 09:00:00');

        $this->nota('2026-08-07');
        $this->pedido('2026-08-07');

        $this->assertSame('desatualizado', app(FrescorDoDado::class)->pior()['status']);
    }

    public function test_segunda_feira_com_nota_de_sexta_continua_verde(): void
    {
        // ⚠️ É POR ISSO QUE A CONTAGEM É EM DIAS ÚTEIS. Em dias corridos são 3 e a pill
        // ficaria amarela TODA segunda-feira, sem nada de errado — e alarme que dispara
        // sozinho toda semana é alarme que se aprende a ignorar.
        Carbon::setTestNow('2026-09-14 09:00:00'); // segunda
        $this->assertSame('Monday', now()->format('l'));

        $this->nota('2026-09-11');   // sexta
        $this->pedido('2026-09-11');

        $frescor = app(FrescorDoDado::class)->pior();

        $this->assertSame(1, $frescor['dias'], 'sábado e domingo não contam');
        $this->assertSame('atualizado', $frescor['status']);
    }

    public function test_tabela_vazia_nao_e_atualizado(): void
    {
        Carbon::setTestNow('2026-09-08 09:00:00');

        // Nenhuma nota, nenhum pedido: ausência de dado não pode passar por saúde.
        $pior = app(FrescorDoDado::class)->pior();

        $this->assertSame('sem_dado', $pior['status']);
        $this->assertNull($pior['dias']);
    }

    public function test_pior_dominio_manda_na_pill(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');

        $this->nota('2026-09-09');   // fresco
        $this->pedido('2026-08-01'); // parado

        $pior = app(FrescorDoDado::class)->pior();

        $this->assertSame('Pedidos', $pior['dominio']);
        $this->assertSame('desatualizado', $pior['status']);
    }

    public function test_painel_entrega_a_prop_no_formato_que_a_pill_le(): void
    {
        // Trava o contrato com o front: a prop deixou de ser lista e virou objeto, e um
        // `statusSistema.length` remanescente no Vue esconderia a pill em silêncio.
        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->nota('2026-09-09');
        $this->pedido('2026-09-09');

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('statusSistema.status')
                ->has('statusSistema.dominio')
                ->has('statusSistema.dias')
                ->has('statusSistema.data')
                ->where('statusSistema.status', 'atualizado')
            );
    }

    public function test_tabela_de_marcacao_nao_existe_mais(): void
    {
        // ⚠️ Esta é a trava contra a reincidência: `data_sync_status` tinha nome plausível
        // e conteúdo fictício, e foi lida como fonte de verdade por um mês. Se alguém a
        // recriar, este teste diz o porquê de ela não dever voltar.
        $this->assertFalse(
            Schema::hasTable('data_sync_status'),
            'a pill sai de FrescorDoDado, que mede o dado; nenhuma tabela de marcação deve voltar'
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
