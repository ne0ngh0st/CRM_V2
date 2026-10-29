<?php

namespace Tests\Feature\Ia;

use App\Models\IaConversa;
use App\Models\IaMensagem;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Card "Consumo e saldo" do /ia/admin: tokens, quanto sobra do teto, projeção e R$.
 *
 * Valores todos diferentes entre si: com números parecidos, trocar uma coluna de
 * token pela outra passaria verde.
 */
class ConsumoIaTest extends IaTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tokens_saldo_projecao_e_reais_do_mes(): void
    {
        Carbon::setTestNow('2026-10-10 15:00:00'); // dia 10 de um mês de 31
        config(['ia.teto_mensal_usd' => 300, 'ia.cotacao_dolar' => 5.0]);

        $admin = $this->usuario('admin');
        $conversa = IaConversa::create(['user_id' => $admin->id, 'titulo' => 't', 'modelo' => 'm']);

        $msg = fn (array $a) => IaMensagem::create(['conversa_id' => $conversa->id, 'texto' => 'x', ...$a]);
        $msg(['papel' => 'usuario']);
        $msg(['papel' => 'ia', 'tokens_entrada' => 1000, 'tokens_saida' => 200, 'tokens_cache_leitura' => 30, 'tokens_cache_escrita' => 4, 'custo_usd' => 10]);
        $msg(['papel' => 'usuario']);
        $msg(['papel' => 'ia', 'tokens_entrada' => 5000, 'tokens_saida' => 700, 'custo_usd' => 20]);

        // Mês passado não entra.
        $velha = $msg(['papel' => 'ia', 'tokens_entrada' => 99999, 'custo_usd' => 50]);
        $velha->forceFill(['created_at' => '2026-09-30 23:00:00'])->save();

        $this->actingAs($admin)->get(route('ia.admin'))->assertInertia(fn (Assert $p) => $p
            ->where('uso.perguntasMes', 2)
            ->where('uso.custoMes', 30)
            ->where('uso.disponivelMes', 270)
            ->where('uso.projecaoMes', 93)       // 30 / 10 dias × 31
            ->where('uso.custoPorPergunta', 15)
            ->where('uso.cotacaoDolar', 5)
            ->where('uso.tokensMes.entrada', 6000)
            ->where('uso.tokensMes.saida', 900)
            ->where('uso.tokensMes.cacheLeitura', 30)
            ->where('uso.tokensMes.cacheEscrita', 4)
            ->where('uso.porUsuario.0.tokens', 6934)
        );
    }

    public function test_disponivel_nao_fica_negativo_quando_passa_do_teto(): void
    {
        config(['ia.teto_mensal_usd' => 5]);
        $admin = $this->usuario('admin');
        $conversa = IaConversa::create(['user_id' => $admin->id, 'titulo' => 't', 'modelo' => 'm']);
        IaMensagem::create(['conversa_id' => $conversa->id, 'papel' => 'ia', 'texto' => 'x', 'custo_usd' => 8]);

        $this->actingAs($admin)->get(route('ia.admin'))->assertInertia(fn (Assert $p) => $p
            ->where('uso.disponivelMes', 0)
            ->where('uso.custoPorPergunta', 0)
        );
    }
}
