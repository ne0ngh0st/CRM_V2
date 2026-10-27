<?php

namespace Tests\Feature\Ia;

use App\Http\Controllers\SimulacaoController;
use App\Models\IaChamado;
use App\Models\IaConversa;
use App\Models\IaMensagem;
use App\Models\Notificacao;
use App\Models\User;
use RuntimeException;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * O caminho de uma pergunta: acesso, limites, o loop de ferramentas e o histórico.
 */
class ConversaIaTest extends IaTestCase
{
    // ------------------------------------------------------------------ acesso

    public function test_beta_tester_usa_e_quem_nao_esta_na_lista_nao(): void
    {
        $this->roteiro([R::texto('oi')]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'oi')->assertOk();
        $this->perguntar($this->usuario('vendedor', '000020', liberado: false), 'oi')->assertForbidden();
    }

    public function test_desligado_so_admin_usa(): void
    {
        config(['ia.habilitado' => false]);
        $this->roteiro([R::texto('oi')]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'oi')->assertForbidden();
        $this->perguntar($this->usuario('admin', liberado: false), 'oi')->assertOk();
    }

    public function test_admin_simulando_vendedor_fora_da_lista_consegue_testar_e_fica_registrado(): void
    {
        $admin = $this->usuario('admin', liberado: false);
        $vendedor = $this->usuario('vendedor', '000010', liberado: false);
        $this->roteiro([R::texto('oi')]);

        $this->actingAs($vendedor)
            ->withSession([SimulacaoController::SESSAO_ADMIN_ID => $admin->id])
            ->postJson(route('ia.enviar'), ['texto' => 'oi'])
            ->assertOk();

        $conversa = IaConversa::sole();
        $this->assertSame($vendedor->id, $conversa->user_id);
        $this->assertSame($admin->id, $conversa->admin_simulando_id);
    }

    public function test_prop_compartilhada_diz_quem_ve_o_botao(): void
    {
        $this->actingAs($this->usuario('vendedor', '000010'))->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('auth.usaIa', true));
        $this->actingAs($this->usuario('vendedor', '000020', liberado: false))->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('auth.usaIa', false));
    }

    // ------------------------------------------------------------------ o loop

    public function test_pergunta_com_ferramenta_grava_a_resposta_e_o_historico(): void
    {
        $this->pedido();
        $this->roteiro([
            R::ferramenta('consultar_pedido', ['numero' => '992086']),
            R::texto('O pedido **992086** está com bloqueio de estoque.'),
        ]);

        $json = $this->perguntar($this->usuario('vendedor', '000010'), 'e o pedido 992086?')->assertOk()->json();

        $resposta = IaMensagem::query()->where('papel', 'ia')->sole();
        $this->assertSame(IaMensagem::STATUS_OK, $resposta->status);
        $this->assertSame('O pedido **992086** está com bloqueio de estoque.', $resposta->texto);
        $this->assertSame(['consultar_pedido'], $resposta->ferramentas);
        // assistente (tool_use) → usuário (tool_result) → assistente (texto)
        $this->assertSame(['assistant', 'user', 'assistant'], array_column($resposta->conteudo_api, 'role'));
        $this->assertGreaterThan(0, (float) $resposta->custo_usd);
        $this->assertStringContainsString('<strong>992086</strong>', $resposta->paraTela()['html']);

        // A pergunta leva o contexto (data, perfil, tela) na mesma mensagem do usuário.
        $primeira = $this->claude->requisicoes[0]['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('<contexto>', $primeira);
        $this->assertStringContainsString('Tela aberta: /carteira', $primeira);
        $this->assertStringEndsWith('e o pedido 992086?', $primeira);

        $this->assertSame($resposta->conversa_id, $json['conversaId']);
    }

    public function test_o_historico_e_reenviado_sem_nenhuma_edicao(): void
    {
        // O Claude Opus 5.5 recusa histórico editado. A segunda pergunta tem que mandar
        // a primeira troca EXATAMENTE como foi da primeira vez, blocos de raciocínio
        // inclusive, e só acrescentar no fim.
        $this->pedido();
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([
            R::ferramenta('consultar_pedido', ['numero' => '992086']),
            R::texto('Está bloqueado.'),
            R::texto('De nada.'),
        ]);

        $conversaId = $this->perguntar($user, 'pedido 992086?')->json('conversaId');
        $this->perguntar($user, 'obrigado', $conversaId)->assertOk();

        $segundaVolta = $this->claude->requisicoes[1]['messages'];
        $terceira = $this->claude->requisicoes[2]['messages'];

        $esperado = [...$segundaVolta, ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Está bloqueado.']]]];
        $this->assertSame($esperado, array_slice($terceira, 0, count($esperado)));
        $this->assertCount(count($esperado) + 1, $terceira);
        $this->assertSame('assinatura-toolu_1', $terceira[1]['content'][0]['signature']);
    }

    public function test_ferramenta_sem_parametro_reenvia_input_como_objeto(): void
    {
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([R::ferramenta('frescor_dos_dados', []), R::texto('Dados de ontem.'), R::texto('ok')]);

        $conversaId = $this->perguntar($user, 'tá atualizado?')->json('conversaId');
        $this->perguntar($user, 'e agora?', $conversaId)->assertOk();

        // `{}` e não `[]`: a API recusa lista no lugar do objeto de entrada.
        $this->assertStringContainsString('"name":"frescor_dos_dados","input":{}', $this->claude->requisicoesJson[2]);
        $this->assertStringNotContainsString('"input":[]', $this->claude->requisicoesJson[2]);
    }

    public function test_na_ultima_volta_o_modelo_e_obrigado_a_responder_em_texto(): void
    {
        config(['ia.max_voltas' => 3]);
        $this->roteiro([
            R::ferramenta('frescor_dos_dados', [], 'toolu_1'),
            R::ferramenta('frescor_dos_dados', [], 'toolu_2'),
            R::texto('Resumo final.'),
        ]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'confere tudo')->assertOk();

        $this->assertCount(3, $this->claude->requisicoes);
        $this->assertArrayNotHasKey('toolChoice', $this->claude->requisicoes[1]);
        $this->assertSame(['type' => 'none'], $this->claude->requisicoes[2]['toolChoice']);
        $this->assertSame('Resumo final.', IaMensagem::query()->where('papel', 'ia')->sole()->texto);
    }

    public function test_system_e_ferramentas_sao_identicos_entre_perguntas_por_causa_do_cache(): void
    {
        $this->roteiro([R::texto('a'), R::texto('b')]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'um');
        $this->perguntar($this->usuario('supervisor', '000100'), 'dois');

        [$a, $b] = $this->claude->requisicoes;
        $this->assertSame($a['system'], $b['system']);
        $this->assertSame($a['tools'], $b['tools']);
        $this->assertSame(['type' => 'ephemeral'], end($a['system'])['cache_control']);
    }

    public function test_recusa_vira_mensagem_educada(): void
    {
        $this->roteiro([new \App\Services\Ia\RespostaClaude([], 'refusal', 'claude-opus-5-5')]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'algo fora')->assertOk();

        $this->assertStringContainsString('Não consigo ajudar', IaMensagem::query()->where('papel', 'ia')->sole()->texto);
    }

    public function test_falha_da_api_marca_erro_e_a_conversa_continua(): void
    {
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([new RuntimeException('API fora'), R::texto('Agora foi.')]);

        $conversaId = $this->perguntar($user, 'primeira')->json('conversaId');
        $falha = IaMensagem::query()->where('papel', 'ia')->sole();
        $this->assertSame(IaMensagem::STATUS_ERRO, $falha->status);
        $this->assertNull($falha->conteudo_api);

        $this->perguntar($user, 'segunda', $conversaId)->assertOk();

        // A resposta que falhou fica fora do histórico; as duas perguntas seguem.
        $this->assertSame(['user', 'user'], array_column($this->claude->requisicoes[1]['messages'], 'role'));
    }

    // ------------------------------------------------------------------ limites

    public function test_limite_diario_por_usuario(): void
    {
        config(['ia.limite_diario_por_usuario' => 1]);
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([R::texto('a')]);

        $this->perguntar($user, 'um')->assertOk();
        $this->perguntar($user, 'dois')->assertStatus(429)->assertJsonPath('erro', fn ($e) => str_contains($e, 'limite'));
    }

    public function test_teto_mensal_para_todo_mundo(): void
    {
        config(['ia.teto_mensal_usd' => 10]);
        $conversa = IaConversa::create(['user_id' => $this->usuario('vendedor', '000020')->id, 'titulo' => 'x', 'modelo' => 'm']);
        $conversa->mensagens()->create(['papel' => 'ia', 'custo_usd' => 11]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'oi')->assertStatus(429);
    }

    public function test_nao_aceita_pergunta_com_resposta_pendente(): void
    {
        $user = $this->usuario('vendedor', '000010');
        $conversa = IaConversa::create(['user_id' => $user->id, 'titulo' => 'x', 'modelo' => 'm']);
        $conversa->mensagens()->create(['papel' => 'ia', 'status' => IaMensagem::STATUS_PROCESSANDO]);

        $this->perguntar($user, 'e aí?', $conversa->id)->assertStatus(409);
    }

    public function test_nao_le_nem_continua_conversa_de_outro(): void
    {
        $dono = $this->usuario('vendedor', '000010');
        $outro = $this->usuario('vendedor', '000020');
        $this->roteiro([R::texto('a')]);
        $conversaId = $this->perguntar($dono, 'minha')->json('conversaId');
        $mensagem = IaMensagem::query()->where('papel', 'ia')->sole();

        $this->actingAs($outro)->getJson(route('ia.conversa', $conversaId))->assertNotFound();
        $this->actingAs($outro)->getJson(route('ia.mensagem', $mensagem))->assertNotFound();
        $this->perguntar($outro, 'invado', $conversaId)->assertNotFound();
    }

    // ------------------------------------------------------------------ chamado

    public function test_chamado_e_registrado_e_so_o_suporte_e_avisado(): void
    {
        $suporte = User::factory()->create(['email' => 'suporte@autopel.com', 'is_active' => true]);
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([
            R::ferramenta('abrir_chamado', ['categoria' => 'dado_errado', 'resumo' => 'Pedido 992086 sem nota.']),
            R::texto('Chamado aberto.'),
        ]);

        $this->perguntar($user, 'abre um chamado', tela: '/pedidos-abertos')->assertOk();

        $chamado = IaChamado::sole();
        $this->assertSame($user->id, $chamado->user_id);
        $this->assertSame('dado_errado', $chamado->categoria);
        $this->assertSame('/pedidos-abertos', $chamado->tela);
        $this->assertSame($chamado->id, $this->resultadoDaFerramenta()['protocolo']);

        $this->assertSame([$suporte->id], Notificacao::query()->where('tipo', 'ia_chamado')->pluck('user_id')->all());
    }

    public function test_admin_responde_o_chamado_e_quem_abriu_e_avisado(): void
    {
        $admin = $this->usuario('admin');
        $user = $this->usuario('vendedor', '000010');
        $chamado = IaChamado::create(['user_id' => $user->id, 'categoria' => 'duvida', 'resumo' => 'x']);

        $this->actingAs($user)->post(route('ia.admin.responder', $chamado), ['resposta' => 'feito'])->assertForbidden();
        $this->actingAs($admin)->post(route('ia.admin.responder', $chamado), ['resposta' => 'Corrigido.'])->assertRedirect();

        $this->assertSame('resolvido', $chamado->fresh()->status);
        $this->assertTrue(Notificacao::query()->where('user_id', $user->id)->where('tipo', 'ia_chamado_resposta')->exists());
    }

    public function test_tela_admin_so_para_admin(): void
    {
        $this->actingAs($this->usuario('vendedor', '000010'))->get(route('ia.admin'))->assertForbidden();
        $this->actingAs($this->usuario('admin'))->get(route('ia.admin'))->assertOk();
    }
}
