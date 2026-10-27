<?php

namespace Tests\Feature\Ia;

use App\Jobs\ResponderMensagemIaJob;
use App\Models\IaMensagem;
use App\Services\Ia\RespostaClaude;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * O botão "Parar": o modelo entendeu errado e o usuário corta a resposta.
 *
 * As três janelas em que o clique pode cair — antes de o job começar, no meio do texto e
 * no instante entre a última conferência e a gravação — têm cada uma um teste, porque
 * cada uma tem um mecanismo diferente.
 */
class CancelamentoIaTest extends IaTestCase
{
    /** Pergunta enviada com o job segurado, para o teste decidir quando ele roda. */
    private function perguntaPendente($user, string $texto = 'pedido 992086?'): array
    {
        Queue::fake();
        $json = $this->perguntar($user, $texto)->assertOk()->json();

        $job = null;
        Queue::assertPushed(ResponderMensagemIaJob::class, function ($j) use (&$job) {
            $job = $j;

            return true;
        });

        return [$json, $job];
    }

    public function test_parar_antes_do_job_comecar_nem_chama_o_modelo(): void
    {
        $user = $this->usuario('vendedor', '000010');
        [$json, $job] = $this->perguntaPendente($user);

        $this->postJson(route('ia.parar', $json['resposta']['id']))
            ->assertOk()
            ->assertJsonPath('cancelou', true)
            ->assertJsonPath('pergunta.texto', 'pedido 992086?');

        $this->roteiro([]); // roteiro vazio: se o modelo for chamado, o teste estoura
        $job->handle();

        $this->assertSame(IaMensagem::STATUS_CANCELADA, IaMensagem::find($json['resposta']['id'])->status);
        $this->assertSame(IaMensagem::STATUS_CANCELADA, IaMensagem::find($json['pergunta']['id'])->status);
    }

    public function test_parar_no_meio_corta_o_loop_e_tudo_sai_do_historico(): void
    {
        $user = $this->usuario('vendedor', '000010');
        $this->pedido();
        [$json, $job] = $this->perguntaPendente($user);

        // A primeira volta pede uma ferramenta; durante ela o usuário aperta "Parar".
        $this->roteiro([
            function () use ($json) {
                $this->postJson(route('ia.parar', $json['resposta']['id']))->assertOk();

                return R::ferramenta('consultar_pedido', ['numero' => '992086']);
            },
            R::texto('esta resposta não deveria existir'),
        ]);
        $job->handle();

        $this->assertCount(1, $this->claude->requisicoes, 'a segunda volta não pode acontecer');
        $resposta = IaMensagem::find($json['resposta']['id']);
        $this->assertSame(IaMensagem::STATUS_CANCELADA, $resposta->status);
        $this->assertNull($resposta->conteudo_api);

        // A próxima pergunta não leva a tentativa descartada.
        $this->perguntar($user, 'quero o pedido 992086 do cliente X', $json['conversaId'])->assertOk();
        $segundo = null;
        Queue::assertPushed(ResponderMensagemIaJob::class, function ($j) use (&$segundo, $job) {
            if ($j !== $job) {
                $segundo = $j;
            }

            return true;
        });
        $this->roteiro([R::texto('agora sim')]);
        $segundo->handle();

        $mensagens = $this->claude->requisicoes[0]['messages'];
        $this->assertCount(1, $mensagens);
        $this->assertStringEndsWith('quero o pedido 992086 do cliente X', $mensagens[0]['content'][0]['text']);
    }

    public function test_parar_enquanto_o_texto_chega_interrompe_a_leitura(): void
    {
        // O caso que importa no modelo lento: o texto já está saindo, errado, e o usuário
        // corta. A leitura do stream para ali — é o que fecha a conexão e para de cobrar.
        $user = $this->usuario('vendedor', '000010');
        [$json, $job] = $this->perguntaPendente($user);

        $escritos = [];
        $this->roteiro([function ($requisicao, callable $aoEscrever) use ($json, &$escritos) {
            $this->postJson(route('ia.parar', $json['resposta']['id']))->assertOk();
            foreach (['O pedido ', 'que você ', 'pediu…'] as $pedaco) {
                $aoEscrever($pedaco);
                $escritos[] = $pedaco;
            }

            return R::texto('O pedido que você pediu…');
        }]);
        $job->handle();

        $this->assertSame([], $escritos, 'o primeiro pedaço já tinha que interromper a leitura');
        $this->assertSame(IaMensagem::STATUS_CANCELADA, IaMensagem::find($json['resposta']['id'])->status);
    }

    public function test_parar_no_ultimo_instante_nao_e_desfeito_pela_gravacao(): void
    {
        // O clique cai DEPOIS da última conferência e ANTES de gravar: a gravação
        // condicional é que segura o cancelamento.
        $user = $this->usuario('vendedor', '000010');
        [$json, $job] = $this->perguntaPendente($user);

        $this->roteiro([function () use ($json) {
            IaMensagem::query()->whereKey($json['resposta']['id'])->update(['status' => IaMensagem::STATUS_CANCELADA]);

            return new RespostaClaude([['type' => 'text', 'text' => 'tarde demais']], 'end_turn', 'claude-opus-5-5');
        }]);
        $job->handle();

        $resposta = IaMensagem::find($json['resposta']['id']);
        $this->assertSame(IaMensagem::STATUS_CANCELADA, $resposta->status);
        $this->assertNull($resposta->texto);
    }

    public function test_resposta_ja_entregue_nao_e_cancelada(): void
    {
        $user = $this->usuario('vendedor', '000010');
        $this->roteiro([R::texto('pronto')]);
        $json = $this->perguntar($user, 'oi')->json();

        $this->postJson(route('ia.parar', $json['resposta']['id']))->assertOk()->assertJsonPath('cancelou', false);

        $this->assertSame(IaMensagem::STATUS_OK, IaMensagem::find($json['resposta']['id'])->status);
        $this->assertSame(IaMensagem::STATUS_OK, IaMensagem::find($json['pergunta']['id'])->status);
    }

    public function test_so_o_dono_para(): void
    {
        [$json] = $this->perguntaPendente($this->usuario('vendedor', '000010'));

        $this->actingAs($this->usuario('vendedor', '000020'))
            ->postJson(route('ia.parar', $json['resposta']['id']))
            ->assertNotFound();

        $this->assertSame(IaMensagem::STATUS_PROCESSANDO, IaMensagem::find($json['resposta']['id'])->status);
    }
}
