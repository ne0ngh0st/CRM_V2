<?php

namespace Tests\Feature;

use App\Models\SolicitacaoEtiqueta;
use App\Models\User;
use App\Services\Solicitacoes\EtiquetaPdfPresenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitacaoEtiquetaPdfTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function solicitacao(User $dono, array $attrs = []): SolicitacaoEtiqueta
    {
        return SolicitacaoEtiqueta::create(array_merge([
            'user_id' => $dono->id,
            'solicitante_nome' => $dono->name,
            'cod_vendedor' => '010772',
            'nomenclatura' => 'ETIQUETA TESTE 40X40',
            'titulo_padronizado' => 'ETIQUETA TESTE 40X40',
            'personalizacao' => 'impresso',
            'unidade_venda' => 'pacote_manual',
            'quantidade_caixa' => 10,
            'metragem' => 500,
            'medidas' => '40X40 SEGURANÇA LATERAIS',
            'diametro_tubete' => 'Ø 25MM',
            'aplicacao' => 'AEROPORTO',
            'tipo_adesivo' => 'REMOVÍVEL',
            'estoque_seguranca_sn' => 'sim',
            'estoque_seguranca' => 20,
            'saida_rolo' => 'f3',
            'observacoes' => 'Cliente pediu urgência.',
            'status' => 'pendente',
        ], $attrs));
    }

    public function test_gera_pdf_de_verdade(): void
    {
        $dono = $this->usuario('vendedor');
        $etiqueta = $this->solicitacao($dono);

        $resposta = $this->actingAs($dono)
            ->get(route('cadastros.etiquetas.pdf', $etiqueta->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    public function test_rotulos_seguem_o_legado(): void
    {
        $dono = $this->usuario('vendedor');
        $etiqueta = $this->solicitacao($dono);
        $dados = app(EtiquetaPdfPresenter::class)->montar($etiqueta);

        $this->assertSame('Sim', $dados['comerciais']['Possui estoque de segurança?']);
        $this->assertSame('20', $dados['comerciais']['Estoque de segurança']);
        $this->assertSame('Impresso', $dados['tecnicas']['Personalização']);
        $this->assertSame('Pacote (manual)', $dados['tecnicas']['Unidade de venda']);
        $this->assertSame('500', $dados['tecnicas']['Metragem total (m)']);

        // Saída de rolo (F1-F4) não entra no título (regra do legado), mas
        // continua aparecendo como bloco próprio com imagem no PDF.
        $this->assertNotNull($dados['saidaRolo']);
        $this->assertSame('F3 - Saída pelo lado esquerdo', $dados['saidaRolo']['rotulo']);
        $this->assertFileExists($dados['saidaRolo']['imagemPath']);
    }

    public function test_campo_vazio_vira_tracinho_em_vez_de_quebrar(): void
    {
        $dono = $this->usuario('vendedor');
        $etiqueta = $this->solicitacao($dono, [
            'medidas' => null,
            'aplicacao' => null,
            'tipo_adesivo' => null,
            'diametro_tubete' => null,
            'estoque_seguranca_sn' => null,
            'saida_rolo' => '', // coluna é NOT NULL; "" é o "sem saída definida" pra esse teste
            'observacoes' => null,
        ]);

        $dados = app(EtiquetaPdfPresenter::class)->montar($etiqueta);
        $this->assertSame('-', $dados['tecnicas']['Medidas (L x A)']);
        $this->assertSame('-', $dados['tecnicas']['Aplicação']);
        $this->assertSame('-', $dados['comerciais']['Possui estoque de segurança?']);
        $this->assertNull($dados['saidaRolo']);
        $this->assertSame('', $dados['observacoes']);

        $this->actingAs($dono)->get(route('cadastros.etiquetas.pdf', $etiqueta->id))->assertOk();
    }

    public function test_status_muda_rotulo_e_cor_do_selo(): void
    {
        $dono = $this->usuario('vendedor');
        $presenter = app(EtiquetaPdfPresenter::class);

        $pendente = $presenter->montar($this->solicitacao($dono, ['status' => 'pendente']));
        $this->assertSame('Pendente', $pendente['statusRotulo']);
        $this->assertSame('#D97706', $pendente['statusCor']);

        $enviada = $presenter->montar($this->solicitacao($dono, ['status' => 'enviado']));
        $this->assertSame('Enviado', $enviada['statusRotulo']);
        $this->assertSame('#16803D', $enviada['statusCor']);
    }

    public function test_vendedor_nao_baixa_ficha_de_outro(): void
    {
        $dono = $this->usuario('vendedor');
        $outro = $this->usuario('vendedor');
        $etiqueta = $this->solicitacao($dono);

        $this->actingAs($outro)
            ->get(route('cadastros.etiquetas.pdf', $etiqueta->id))
            ->assertForbidden();
    }

    public function test_gestor_baixa_ficha_de_qualquer_um(): void
    {
        $dono = $this->usuario('vendedor');
        $etiqueta = $this->solicitacao($dono);

        foreach (['admin', 'diretor', 'supervisor'] as $role) {
            $this->actingAs($this->usuario($role))
                ->get(route('cadastros.etiquetas.pdf', $etiqueta->id))
                ->assertOk();
        }
    }
}
