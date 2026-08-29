<?php

namespace Tests\Feature;

use App\Models\SolicitacaoBobina;
use App\Models\User;
use App\Services\Cadastros\SolicitacaoTituloResolver;
use App\Services\Solicitacoes\BobinaPdfPresenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitacaoBobinaPdfTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function solicitacao(User $dono, array $attrs = []): SolicitacaoBobina
    {
        return SolicitacaoBobina::create(array_merge([
            'user_id' => $dono->id,
            'solicitante_nome' => $dono->name,
            'cod_vendedor' => '010772',
            'nomenclatura' => 'BOBINA TESTE 80X40',
            'titulo_padronizado' => 'TERMICO 80X40 T12',
            'personalizacao' => 'sem_impressao',
            'unidade_venda' => 'caixa',
            'quantidade_caixa' => 30,
            'papel' => 'termicco',
            'gramatura' => '48',
            'largura' => 80,
            'metragem' => 40.5,
            'diametro_tubete' => 12,
            'estoque_seguranca_sn' => 'sim',
            'estoque_seguranca' => 100,
            'impressao' => 'frente_lado_termico',
            'rebobinamento' => 'lado_termico_fora',
            'tubete_obrigatorio' => 'sim',
            'nf_pedido_tipo' => 'venda',
            'observacoes' => 'Cliente pediu urgência.',
            'status' => 'pendente',
        ], $attrs));
    }

    public function test_gera_pdf_de_verdade(): void
    {
        $dono = $this->usuario('vendedor');
        $bobina = $this->solicitacao($dono);

        $resposta = $this->actingAs($dono)
            ->get(route('cadastros.bobinas.pdf', $bobina->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // %PDF- é a assinatura do formato: garante binário válido, não HTML de erro.
        // (o stream() do dompdf devolve Response comum, não StreamedResponse)
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    /**
     * Regressão: `float` dentro do rodapé `position: fixed` faz o dompdf perder a conta
     * da altura da página — o PDF saía com 11 páginas, todas em branco menos o header,
     * com o conteúdo inteiro sumido. Como o arquivo continuava sendo um PDF válido de
     * ~880 KB, a asserção de assinatura `%PDF-` passava e não pegava nada.
     */
    public function test_cabe_em_uma_pagina_e_traz_o_conteudo(): void
    {
        $dono = $this->usuario('vendedor');
        $bobina = $this->solicitacao($dono);

        $conteudo = $this->actingAs($dono)
            ->get(route('cadastros.bobinas.pdf', $bobina->id))
            ->getContent();

        $paginas = preg_match_all('#/Type\s*/Page[^s]#', $conteudo);
        $this->assertSame(1, $paginas, "PDF deveria ter 1 página, tem {$paginas} — layout quebrou.");

        // Um PDF só de header/rodapé pesa bem menos que um com as 4 seções preenchidas.
        $this->assertGreaterThan(3000, strlen(gzcompress($conteudo)), 'PDF parece vazio de conteúdo.');
    }

    public function test_rotulos_seguem_o_legado(): void
    {
        $dono = $this->usuario('vendedor');
        $bobina = $this->solicitacao($dono);
        $dados = app(BobinaPdfPresenter::class)->montar($bobina);

        // NCM faz parte do rótulo no legado — o Cadastro usa isso pra abrir o item.
        $this->assertSame('Venda – NCM 48119010', $dados['comerciais']['NF pedido tipo']);
        $this->assertSame('Térmico', $dados['tecnicas']['Papel']);
        $this->assertSame('48 g/m²', $dados['tecnicas']['Gramatura']);
        $this->assertSame('Sim', $dados['tecnicas']['Uso obrigatório de tubete']);
        $this->assertSame('Sim', $dados['comerciais']['Possui estoque de segurança?']);

        // O PDF recalcula o título na hora (igual `bobina_titulo_da_solicitacao()` do
        // legado), não confia só no `titulo_padronizado` gravado — por isso o esperado
        // aqui vem do próprio resolver, não de uma string fixa.
        $tituloEsperado = app(SolicitacaoTituloResolver::class)->bobina(
            $bobina->nomenclatura,
            $bobina->papel,
            (string) $bobina->largura,
            (float) $bobina->metragem,
            $bobina->gramatura,
        );
        $this->assertSame($tituloEsperado, $dados['tituloDestaque']);

        // Número inteiro sem casas; decimal sem zero à direita — regra do legado.
        $this->assertSame('80', $dados['tecnicas']['Largura (mm)']);
        $this->assertSame('40,5', $dados['tecnicas']['Metragem (m)']);
    }

    public function test_campo_vazio_vira_tracinho_em_vez_de_quebrar(): void
    {
        $dono = $this->usuario('vendedor');
        $bobina = $this->solicitacao($dono, [
            'papel' => null,
            'impressao' => null,
            'tubete_obrigatorio' => null,
            'metragem' => null,
            'estoque_seguranca_sn' => null,
            'observacoes' => null,
        ]);

        $dados = app(BobinaPdfPresenter::class)->montar($bobina);
        $this->assertSame('-', $dados['tecnicas']['Papel']);
        $this->assertSame('-', $dados['tecnicas']['Uso obrigatório de tubete']);
        $this->assertSame('-', $dados['tecnicas']['Metragem (m)']);
        $this->assertSame('-', $dados['comerciais']['Possui estoque de segurança?']);

        $this->actingAs($dono)->get(route('cadastros.bobinas.pdf', $bobina->id))->assertOk();
    }

    public function test_status_muda_rotulo_e_cor_do_selo(): void
    {
        $dono = $this->usuario('vendedor');
        $presenter = app(BobinaPdfPresenter::class);

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
        $bobina = $this->solicitacao($dono);

        $this->actingAs($outro)
            ->get(route('cadastros.bobinas.pdf', $bobina->id))
            ->assertForbidden();
    }

    public function test_gestor_baixa_ficha_de_qualquer_um(): void
    {
        $dono = $this->usuario('vendedor');
        $bobina = $this->solicitacao($dono);

        foreach (['admin', 'diretor', 'supervisor'] as $role) {
            $this->actingAs($this->usuario($role))
                ->get(route('cadastros.bobinas.pdf', $bobina->id))
                ->assertOk();
        }
    }
}
