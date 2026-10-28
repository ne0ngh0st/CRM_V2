<?php

namespace Tests\Feature\Ia;

use App\Models\IaConversa;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A tela cheia do assistente (/ia): mesmo gate do painel, e a lista de conversas é só
 * da própria pessoa.
 */
class TelaCheiaIaTest extends IaTestCase
{
    public function test_quem_usa_o_assistente_abre_a_tela_cheia_e_quem_nao_usa_nao(): void
    {
        $this->actingAs($this->usuario('vendedor', '000010'))
            ->get(route('ia.tela', ['conversa' => 7]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Assistente/Index')->where('conversaInicial', 7));

        $this->actingAs($this->usuario('vendedor', '000020', liberado: false))
            ->get(route('ia.tela'))
            ->assertForbidden();
    }

    public function test_lista_de_conversas_aceita_limite_e_so_traz_as_proprias(): void
    {
        $eu = $this->usuario('vendedor', '000010');
        $outro = $this->usuario('vendedor', '000020');
        foreach (range(1, 25) as $i) {
            IaConversa::create(['user_id' => $eu->id, 'titulo' => "minha {$i}", 'modelo' => 'x']);
        }
        IaConversa::create(['user_id' => $outro->id, 'titulo' => 'alheia', 'modelo' => 'x']);

        $this->actingAs($eu)->getJson(route('ia.conversas'))->assertJsonCount(20);

        $todas = $this->actingAs($eu)->getJson(route('ia.conversas', ['limite' => 200]))
            ->assertJsonCount(25)
            ->json();
        $this->assertNotContains('alheia', array_column($todas, 'titulo'));
        $this->assertNotNull($todas[0]['atualizadaEm']);
    }
}
