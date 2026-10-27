<?php

namespace App\Services\Ia;

/**
 * A única porta do sistema para a API da Anthropic.
 *
 * Existe para que nada além da implementação real conheça o SDK: o orquestrador fala com
 * esta interface e os testes trocam por um roteiro (`Tests\Support\ClienteClaudeRoteirizado`)
 * — a suíte nunca gasta dinheiro nem depende de rede.
 */
interface ClienteClaude
{
    /**
     * Uma volta: manda a conversa, recebe a resposta inteira.
     *
     * @param  array<string, mixed>  $requisicao  parâmetros nomeados do SDK (model, maxTokens,
     *                                            system, tools, messages, outputConfig…), com
     *                                            blocos no formato da API (snake_case)
     * @param  callable(string): void  $aoEscrever  recebe cada pedaço de texto, à medida que chega
     */
    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude;
}
