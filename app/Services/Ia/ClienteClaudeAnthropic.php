<?php

namespace App\Services\Ia;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\RequestOptions;

/**
 * Implementação real, com o SDK oficial (`anthropic-ai/sdk`).
 *
 * Usa o endpoint BETA de mensagens só por causa do `fallbacks`: no Claude Opus 5.5 uma
 * recusa dos classificadores de segurança é reexecutada em outro modelo dentro da mesma
 * chamada, em vez de virar uma resposta vazia para o vendedor.
 *
 * Sempre em streaming: o texto aparece na tela enquanto é escrito (Regra de ouro nº 9 —
 * a primeira palavra em ~1 s vale mais que a resposta inteira em 6 s).
 */
final class ClienteClaudeAnthropic implements ClienteClaude
{
    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude
    {
        $cliente = new Client(apiKey: config('ia.api_key'));

        $stream = $cliente->beta->messages->createStream(
            ...$requisicao,
            requestOptions: RequestOptions::with(timeout: 90, maxRetries: 2),
        );

        $acumulador = MessageAccumulator::forBetaMessages();
        foreach ($stream as $evento) {
            $acumulador->accumulate($evento);

            if ($evento instanceof BetaRawContentBlockDeltaEvent && $evento->delta instanceof BetaTextDelta) {
                $aoEscrever($evento->delta->text);
            }
        }

        $mensagem = $acumulador->message();

        /** @var list<array<string, mixed>> $conteudo */
        $conteudo = json_decode(json_encode($mensagem->content), true) ?? [];

        return new RespostaClaude(
            conteudo: $conteudo,
            stopReason: $mensagem->stopReason,
            modelo: $mensagem->model instanceof \BackedEnum ? (string) $mensagem->model->value : (string) $mensagem->model,
            tokensEntrada: (int) $mensagem->usage->inputTokens,
            tokensSaida: (int) $mensagem->usage->outputTokens,
            tokensCacheLeitura: (int) ($mensagem->usage->cacheReadInputTokens ?? 0),
            tokensCacheEscrita: (int) ($mensagem->usage->cacheCreationInputTokens ?? 0),
        );
    }
}
