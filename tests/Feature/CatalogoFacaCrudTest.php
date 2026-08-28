<?php

namespace Tests\Feature;

use App\Models\Faca;
use App\Models\FacaRecurso;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogoFacaCrudTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function faca(array $attrs = []): Faca
    {
        return Faca::create(array_merge([
            'tipo' => 'balanca',
            'item' => 1,
            'largura' => '40',
            'altura' => '25',
        ], $attrs));
    }

    public function test_admin_cadastra_faca(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.store'), [
                'tipo' => 'tags',
                'item' => 99,
                'largura' => '0/160',
                'altura' => '25',
                'observacao' => 'Largura móvel',
            ])
            ->assertRedirect();

        $faca = Faca::where('tipo', 'tags')->where('item', 99)->first();
        $this->assertNotNull($faca);
        // Medida não-numérica precisa sobreviver — é dado real do catálogo.
        $this->assertSame('0/160', $faca->largura);
    }

    public function test_cadastro_devolve_o_id_pra_tela_anexar_imagem_na_sequencia(): void
    {
        Storage::fake('public');
        $admin = $this->usuario('admin');

        // 1) cadastra e recebe o id de volta (é o que o modal usa pra subir a fila)
        $this->actingAs($admin)
            ->post(route('catalogo-facas.store'), ['tipo' => 'rotulos', 'item' => 42])
            ->assertSessionHas('recursoCriadoId');

        $novoId = session('recursoCriadoId');
        $this->assertSame(Faca::where('tipo', 'rotulos')->where('item', 42)->value('id'), $novoId);

        // 2) sobe a imagem usando esse id, sem precisar reencontrar a faca na listagem
        $this->actingAs($admin)
            ->post(route('catalogo-facas.recursos.store', $novoId), [
                'imagem' => UploadedFile::fake()->image('arte-nova.png'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Faca::find($novoId)->recursos()->count());
    }

    public function test_item_duplicado_no_mesmo_catalogo_e_rejeitado(): void
    {
        $this->faca(['tipo' => 'balanca', 'item' => 7]);

        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.store'), ['tipo' => 'balanca', 'item' => 7])
            ->assertSessionHasErrors('item');

        // Mesmo número em OUTRO catálogo é válido.
        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.store'), ['tipo' => 'tags', 'item' => 7])
            ->assertSessionHasNoErrors();
    }

    public function test_admin_anexa_imagem_e_ela_vai_pro_storage(): void
    {
        Storage::fake('public');
        $faca = $this->faca();

        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.recursos.store', $faca->id), [
                'descricao' => 'Corte retangular',
                'imagem' => UploadedFile::fake()->image('faca-nova.png', 200, 200),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $recurso = $faca->recursos()->first();
        $this->assertSame('Corte retangular', $recurso->descricao);

        /*
         * Guarda o CAMINHO NO DISCO ('facas/x.png'), não uma URL pronta.
         *
         * Até 2026-08-27 gravava 'storage/facas/x.png' — uma URL já montada, que só
         * funciona com disco local. Com o S3 em produção a URL é outra, então ela passou
         * a ser derivada na leitura (CatalogoFacaController::urlDaImagem).
         *
         * O que continua valendo, e é o ponto deste teste: o upload NUNCA vai para
         * public/images/facas, que é versionado no git e recriado a cada release.
         */
        $this->assertStringStartsWith('facas/', $recurso->imagem);
        $this->assertStringNotContainsString('images/', $recurso->imagem);
        Storage::disk('public')->assertExists($recurso->imagem);
    }

    /**
     * A coluna `imagem` tem três formatos convivendo, e a tela precisa exibir os três.
     * Sem isto, uma migration de formato passaria despercebida até alguém abrir o
     * catálogo e ver imagem quebrada.
     */
    public function test_url_da_imagem_cobre_os_tres_formatos(): void
    {
        Storage::fake('public');
        $faca = $this->faca();

        // 1. asset versionado (veio do legado)  2. upload no formato antigo  3. upload novo
        $faca->recursos()->create(['descricao' => 'legado', 'imagem' => 'images/facas/balanca/a.png', 'ordem' => 1]);
        $faca->recursos()->create(['descricao' => 'antigo', 'imagem' => 'storage/facas/b.png', 'ordem' => 2]);
        $faca->recursos()->create(['descricao' => 'novo', 'imagem' => 'facas/c.png', 'ordem' => 3]);

        $this->actingAs($this->usuario('admin'))
            ->get(route('catalogo-facas.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $recursos = collect($page->toArray()['props']['facas'])
                    ->flatMap(fn ($f) => $f['recursos'])
                    ->keyBy('descricao');

                $this->assertSame('/images/facas/balanca/a.png', $recursos['legado']['imagem']);
                $this->assertSame('/storage/facas/b.png', $recursos['antigo']['imagem']);
                $this->assertStringContainsString('facas/c.png', $recursos['novo']['imagem']);
            });
    }

    public function test_recurso_exige_descricao_ou_imagem(): void
    {
        $faca = $this->faca();

        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.recursos.store', $faca->id), [])
            ->assertSessionHasErrors('descricao');
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado(): void
    {
        Storage::fake('public');
        $faca = $this->faca();

        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.recursos.store', $faca->id), [
                'imagem' => UploadedFile::fake()->create('webshell.php', 10, 'application/x-php'),
            ])
            ->assertSessionHasErrors('imagem');

        $this->assertSame(0, $faca->recursos()->count());
    }

    public function test_excluir_faca_apaga_imagem_enviada_mas_nao_a_do_legado(): void
    {
        Storage::fake('public');
        $faca = $this->faca();

        $this->actingAs($this->usuario('admin'))
            ->post(route('catalogo-facas.recursos.store', $faca->id), [
                'imagem' => UploadedFile::fake()->image('nova.png'),
            ]);

        $enviada = $faca->recursos()->first()->imagem;
        // Recurso que veio do seeder aponta pra public/images (versionado no git).
        $doLegado = FacaRecurso::create([
            'faca_id' => $faca->id,
            'descricao' => 'Laterais',
            'imagem' => 'images/facas/balanca/LATERAIS.png',
        ]);

        $this->actingAs($this->usuario('admin'))
            ->delete(route('catalogo-facas.destroy', $faca->id))
            ->assertRedirect();

        $this->assertNull(Faca::find($faca->id));
        $this->assertNull(FacaRecurso::find($doLegado->id));
        Storage::disk('public')->assertMissing(str_replace('storage/', '', $enviada));
        // O arquivo versionado continua no repo — só o registro no banco saiu.
        $this->assertFileExists(public_path('images/facas/balanca/LATERAIS.png'));
    }

    public function test_nao_admin_nao_gerencia(): void
    {
        $faca = $this->faca();

        foreach (['vendedor', 'supervisor', 'diretor'] as $role) {
            $this->actingAs($this->usuario($role))
                ->post(route('catalogo-facas.store'), ['tipo' => 'tags', 'item' => 50])
                ->assertForbidden();
        }

        $this->actingAs($this->usuario('supervisor'))
            ->delete(route('catalogo-facas.destroy', $faca->id))
            ->assertForbidden();

        $this->assertNotNull(Faca::find($faca->id));
    }

    public function test_pagina_expoe_pode_gerenciar_conforme_o_perfil(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->get(route('catalogo-facas.index'))
            ->assertInertia(fn ($page) => $page->where('podeGerenciar', true));

        $this->actingAs($this->usuario('vendedor'))
            ->get(route('catalogo-facas.index'))
            ->assertInertia(fn ($page) => $page->where('podeGerenciar', false));
    }
}
