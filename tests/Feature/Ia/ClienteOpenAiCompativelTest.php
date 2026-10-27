<?php

namespace Tests\Feature\Ia;

use App\Models\IaMensagem;
use App\Services\Ia\ClienteClaude;
use App\Services\Ia\ClienteClaudeBedrock;
use App\Services\Ia\ClienteOpenAiCompativel;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

/**
 * O tradutor do modelo local (Ollama): a conversa vive no formato da Anthropic, o
 * servidor local fala o da OpenAI. Errar a tradução quebra a conversa na segunda volta —
 * o modelo receberia o resultado de uma ferramenta que ele "não chamou".
 */
class ClienteOpenAiCompativelTest extends IaTestCase
{
    private function sse(array $pedacos): string
    {
        return implode('', array_map(fn ($p) => 'data: '.json_encode($p)."\n\n", $pedacos))."data: [DONE]\n\n";
    }

    public function test_traduz_historico_com_ferramenta_para_o_formato_openai(): void
    {
        config(['ia.local.modelo' => 'qwen2.5:7b']);

        $corpo = (new ClienteOpenAiCompativel)->corpo([
            'system' => [['type' => 'text', 'text' => 'instruções'], ['type' => 'text', 'text' => 'manual', 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => [['name' => 'consultar_pedido', 'description' => 'd', 'input_schema' => ['type' => 'object'], 'strict' => true]],
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'pedido 1?']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'consultar_pedido', 'input' => ['numero' => '1']],
                ]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"encontrado":true}']]],
            ],
            'toolChoice' => ['type' => 'none'],
            'maxTokens' => 1000,
        ]);

        $this->assertSame('qwen2.5:7b', $corpo['model']);
        $this->assertSame(['role' => 'system', 'content' => "instruções\n\nmanual"], $corpo['messages'][0]);
        $this->assertSame(['role' => 'user', 'content' => 'pedido 1?'], $corpo['messages'][1]);
        // O raciocínio some; a chamada vira tool_calls com o MESMO id do tool_result.
        $this->assertSame('assistant', $corpo['messages'][2]['role']);
        $this->assertSame('toolu_1', $corpo['messages'][2]['tool_calls'][0]['id']);
        $this->assertSame('{"numero":"1"}', $corpo['messages'][2]['tool_calls'][0]['function']['arguments']);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => '{"encontrado":true}'], $corpo['messages'][3]);
        $this->assertSame('consultar_pedido', $corpo['tools'][0]['function']['name']);
        $this->assertArrayNotHasKey('strict', $corpo['tools'][0]['function']);
        $this->assertSame('none', $corpo['tool_choice']);
    }

    public function test_chamada_inteira_no_estilo_ollama_vira_tool_use(): void
    {
        $stream = Utils::streamFor($this->sse([
            ['choices' => [['delta' => ['tool_calls' => [['id' => 'call_9', 'function' => ['name' => 'frescor_dos_dados', 'arguments' => '{}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 7]],
        ]));

        $r = (new ClienteOpenAiCompativel)->lerStream($stream, fn () => null);

        $this->assertSame('tool_use', $r->stopReason);
        $this->assertSame('call_9', $r->conteudo[0]['id']);
        $this->assertSame('frescor_dos_dados', $r->conteudo[0]['name']);
        // `{}` precisa sair como objeto, nunca como lista.
        $this->assertSame('{}', json_encode($r->conteudo[0]['input']));
        $this->assertSame(50, $r->tokensEntrada);
    }

    public function test_argumentos_fatiados_no_estilo_openai_sao_remontados(): void
    {
        $stream = Utils::streamFor($this->sse([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'consultar_pedido', 'arguments' => '{"num']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ero":"992086"}']]]]]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]));

        $r = (new ClienteOpenAiCompativel)->lerStream($stream, fn () => null);

        $this->assertSame(['numero' => '992086'], $r->conteudo[0]['input']);
    }

    public function test_texto_chega_aos_pedacos_e_termina_em_end_turn(): void
    {
        $pedacos = [];
        $stream = Utils::streamFor($this->sse([
            ['choices' => [['delta' => ['content' => 'O pedido ']]]],
            ['choices' => [['delta' => ['content' => 'está liberado.'], 'finish_reason' => 'stop']]],
        ]));

        $r = (new ClienteOpenAiCompativel)->lerStream($stream, function ($p) use (&$pedacos) {
            $pedacos[] = $p;
        });

        $this->assertSame(['O pedido ', 'está liberado.'], $pedacos);
        $this->assertSame('end_turn', $r->stopReason);
        $this->assertSame([['type' => 'text', 'text' => 'O pedido está liberado.']], $r->conteudo);
    }

    public function test_pergunta_de_ponta_a_ponta_com_o_provedor_local(): void
    {
        config(['ia.provedor' => 'local', 'ia.local.url' => 'http://ollama.test/v1']);
        $this->app->forgetInstance(ClienteClaude::class);
        $this->pedido();

        Http::fake(['ollama.test/*' => Http::sequence()
            ->push($this->sse([
                ['choices' => [['delta' => ['tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'consultar_pedido', 'arguments' => '{"numero":"992086"}']]]], 'finish_reason' => 'tool_calls']]],
            ]))
            ->push($this->sse([
                ['choices' => [['delta' => ['content' => 'Está com bloqueio de estoque.'], 'finish_reason' => 'stop']]],
            ])),
        ]);

        $this->perguntar($this->usuario('vendedor', '000010'), 'pedido 992086?')->assertOk();

        $resposta = IaMensagem::query()->where('papel', 'ia')->sole();
        $this->assertSame('Está com bloqueio de estoque.', $resposta->texto);
        $this->assertSame(['consultar_pedido'], $resposta->ferramentas);
        $this->assertSame('0.000000', $resposta->custo_usd);

        // A segunda chamada levou o resultado REAL da ferramenta, no formato `tool`.
        Http::assertSent(fn ($req) => collect($req['messages'])->contains(
            fn ($m) => $m['role'] === 'tool' && str_contains($m['content'], '"encontrado":true')
        ));
    }

    public function test_provedor_local_e_recusado_em_producao(): void
    {
        config(['ia.provedor' => 'local', 'ia.api_key' => 'x']);
        $this->app['env'] = 'production';

        $this->assertNotInstanceOf(ClienteOpenAiCompativel::class, $this->app->make(ClienteClaude::class));
    }

    public function test_provedor_bedrock_e_o_sonnet_mesmo_sem_chave_da_anthropic(): void
    {
        config([
            'ia.provedor' => 'bedrock',
            'ia.modelo' => 'us.anthropic.claude-sonnet-4-6',
            'ia.api_key' => '',
        ]);
        $this->app['env'] = 'production';
        $this->app->forgetInstance(ClienteClaude::class);

        $this->assertInstanceOf(ClienteClaudeBedrock::class, $this->app->make(ClienteClaude::class));
        $this->assertSame('us.anthropic.claude-sonnet-4-6', config('ia.modelo'));
    }
}
