<?php

namespace Tests\Unit\Ia;

use App\Services\Ia\ClienteClaudeBedrock;
use PHPUnit\Framework\TestCase;

/**
 * A tradução Anthropic → Converse. Errar aqui faz o Sonnet receber um histórico
 * que ele não reconhece na segunda volta (ferramenta "que ele não chamou").
 */
class ClienteClaudeBedrockTest extends TestCase
{
    public function test_traduz_historico_e_usa_o_modelo_da_requisicao(): void
    {
        $params = (new ClienteClaudeBedrock)->parametros([
            'model' => 'us.anthropic.claude-sonnet-4-6',
            'maxTokens' => 1000,
            'system' => [
                ['type' => 'text', 'text' => 'instruções'],
                ['type' => 'text', 'text' => 'manual', 'cache_control' => ['type' => 'ephemeral']],
            ],
            'tools' => [[
                'name' => 'consultar_pedido',
                'description' => 'd',
                'input_schema' => ['type' => 'object'],
                'strict' => true,
            ]],
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'pedido 1?']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'consultar_pedido', 'input' => ['numero' => '1']],
                ]],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => 'toolu_1',
                    'content' => '{"encontrado":true}',
                ]]],
            ],
            'toolChoice' => ['type' => 'none'],
            'betas' => ['server-side-fallback-2026-07-01'],
            'fallbacks' => 'default',
        ]);

        $this->assertSame('us.anthropic.claude-sonnet-4-6', $params['modelId']);
        $this->assertSame([
            ['text' => 'instruções'],
            ['text' => 'manual'],
        ], $params['system']);
        $this->assertArrayNotHasKey('betas', $params);
        $this->assertSame('none', array_key_first($params['toolConfig']['toolChoice']));
        $this->assertSame('consultar_pedido', $params['toolConfig']['tools'][0]['toolSpec']['name']);
        $this->assertArrayNotHasKey('strict', $params['toolConfig']['tools'][0]['toolSpec']);

        $assistente = $params['messages'][1]['content'][0]['toolUse'];
        $this->assertSame('toolu_1', $assistente['toolUseId']);
        $this->assertSame(['numero' => '1'], $assistente['input']);

        $resultado = $params['messages'][2]['content'][0]['toolResult'];
        $this->assertSame('toolu_1', $resultado['toolUseId']);
        $this->assertSame('{"encontrado":true}', $resultado['content'][0]['text']);
    }

    public function test_stream_vira_texto_e_chamada_de_ferramenta(): void
    {
        $pedacos = [];
        $resposta = (new ClienteClaudeBedrock)->acumular([
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Vou ']]],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'consultar.']]],
            ['contentBlockStart' => ['contentBlockIndex' => 1, 'start' => ['toolUse' => [
                'toolUseId' => 'toolu_9',
                'name' => 'consultar_pedido',
            ]]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 1, 'delta' => ['toolUse' => ['input' => '{"numero":']]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 1, 'delta' => ['toolUse' => ['input' => '"992086"}']]]],
            ['messageStop' => ['stopReason' => 'tool_use']],
            ['metadata' => ['usage' => ['inputTokens' => 12, 'outputTokens' => 4]]],
        ], function (string $p) use (&$pedacos) {
            $pedacos[] = $p;
        }, 'us.anthropic.claude-sonnet-4-6');

        $this->assertSame(['Vou ', 'consultar.'], $pedacos);
        $this->assertSame('tool_use', $resposta->stopReason);
        $this->assertSame('us.anthropic.claude-sonnet-4-6', $resposta->modelo);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Vou consultar.'],
            ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'consultar_pedido', 'input' => ['numero' => '992086']],
        ], $resposta->conteudo);
        $this->assertSame(12, $resposta->tokensEntrada);
        $this->assertSame(4, $resposta->tokensSaida);
    }
}
