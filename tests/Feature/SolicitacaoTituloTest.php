<?php

namespace Tests\Feature;

use App\Models\SolicitacaoBobina;
use App\Models\SolicitacaoEtiqueta;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As regras de nome em si estão travadas em tests/Unit/SolicitacaoTituloResolverTest.
 * Aqui o que se testa é a LIGAÇÃO controller→resolver: era justamente onde estava o
 * erro (a gramatura nunca era passada, e o lugar da metragem ia a saída de rolo).
 */
class SolicitacaoTituloTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        return $user;
    }

    public function test_titulo_da_bobina_usa_a_gramatura_enviada(): void
    {
        $this->actingAs($this->vendedor())
            ->post(route('cadastros.bobinas.store'), [
                'nomenclatura' => 'CLIENTE X',
                'papel' => 'termicco',
                'gramatura' => '44',
                'largura' => '80',
                'metragem' => 30,
            ])
            ->assertRedirect();

        $this->assertSame(
            'BOBINA TS KPH BC 80X30M CLIENTE X',
            SolicitacaoBobina::sole()->titulo_padronizado,
        );
    }

    public function test_titulo_da_etiqueta_usa_a_metragem_e_ignora_a_saida_de_rolo(): void
    {
        $this->actingAs($this->vendedor())
            ->post(route('cadastros.etiquetas.store'), [
                'nomenclatura' => 'BARONESA',
                'medidas' => '40X40 SEGURANÇA LATERAIS',
                'tipo_adesivo' => 'Térmico Borracha com Barreira',
                'metragem' => 30,
                'saida_rolo' => 'f3',
            ])
            ->assertRedirect();

        $etiqueta = SolicitacaoEtiqueta::sole();

        $this->assertSame(
            'ETIQUETA SEGURANÇA LATERAIS 40X40X30M BARONESA - TÉRMICO BORRACHA COM BARREIRA',
            $etiqueta->titulo_padronizado,
        );

        // A saída de rolo continua sendo gravada — só não compõe o título.
        $this->assertSame('f3', $etiqueta->saida_rolo);
    }

    public function test_comando_recalcula_titulos_ja_gravados(): void
    {
        $dono = $this->vendedor();

        $bobina = SolicitacaoBobina::create([
            'user_id' => $dono->id,
            'solicitante_nome' => $dono->name,
            'nomenclatura' => 'CLIENTE X',
            'titulo_padronizado' => 'BOBINA TERMICO 80X30M CLIENTE X', // regra antiga
            'papel' => 'termicco',
            'gramatura' => '44',
            'largura' => 80,
            'metragem' => 30,
            'status' => 'pendente',
        ]);

        $etiqueta = SolicitacaoEtiqueta::create([
            'user_id' => $dono->id,
            'solicitante_nome' => $dono->name,
            'nomenclatura' => 'BARONESA',
            'titulo_padronizado' => 'ETIQUETA 40X40SEGURANÇALATERAIS ACRÍLICO F1 BARONESA', // regra antiga
            'medidas' => '40X40 SEGURANÇA LATERAIS',
            'tipo_adesivo' => 'Acrílico',
            'metragem' => 30,
            'saida_rolo' => 'f1',
            'status' => 'pendente',
        ]);

        $this->artisan('cadastros:recalcular-titulos')->assertSuccessful();

        $this->assertSame(
            'BOBINA TS KPH BC 80X30M CLIENTE X',
            $bobina->refresh()->titulo_padronizado,
        );

        $this->assertSame(
            'ETIQUETA SEGURANÇA LATERAIS 40X40X30M BARONESA - ACRÍLICO',
            $etiqueta->refresh()->titulo_padronizado,
        );
    }

    public function test_dry_run_nao_grava(): void
    {
        $dono = $this->vendedor();

        $bobina = SolicitacaoBobina::create([
            'user_id' => $dono->id,
            'solicitante_nome' => $dono->name,
            'nomenclatura' => 'CLIENTE X',
            'titulo_padronizado' => 'TITULO ANTIGO',
            'papel' => 'termicco',
            'gramatura' => '44',
            'largura' => 80,
            'metragem' => 30,
            'status' => 'pendente',
        ]);

        $this->artisan('cadastros:recalcular-titulos', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('TITULO ANTIGO', $bobina->refresh()->titulo_padronizado);
    }
}
