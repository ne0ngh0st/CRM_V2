<?php

namespace App\Services\Ia;

use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * Claude no Amazon Bedrock (Converse), com a credencial padrão da AWS.
 *
 * O resto do sistema continua falando o formato da API da Anthropic — inclusive o
 * histórico gravado. Esta classe traduz na ida e na volta. O modelo é o que vier
 * em `model` na requisição (`config('ia.modelo')`), não um default escondido aqui.
 *
 * Campos que só existem na API direta da Anthropic (`betas`, `fallbacks`,
 * `outputConfig`, `cacheControl`) não são enviados: o Converse não os aceita.
 */
final class ClienteClaudeBedrock implements ClienteClaude
{
    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude
    {
        $cliente = new BedrockRuntimeClient([
            'region' => (string) config('ia.regiao'),
            'version' => 'latest',
            'http' => ['timeout' => 120, 'connect_timeout' => 10],
        ]);

        $resultado = $cliente->converseStream($this->parametros($requisicao));

        return $this->acumular($resultado['stream'], $aoEscrever, (string) $requisicao['model']);
    }

    /**
     * A requisição no formato do Converse. Público para o teste conferir a tradução sem rede.
     *
     * @param  array<string, mixed>  $requisicao
     * @return array<string, mixed>
     */
    public function parametros(array $requisicao): array
    {
        $sistema = [];
        $blocos = is_array($requisicao['system'] ?? null) ? $requisicao['system'] : [$requisicao['system'] ?? ''];
        foreach ($blocos as $bloco) {
            $texto = is_array($bloco) ? (string) ($bloco['text'] ?? '') : (string) $bloco;
            if ($texto !== '') {
                $sistema[] = ['text' => $texto];
            }
        }

        $mensagens = [];
        foreach ($requisicao['messages'] as $mensagem) {
            $conteudo = $this->conteudo($mensagem);
            if ($conteudo !== []) {
                $mensagens[] = [
                    'role' => ($mensagem['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                    'content' => $conteudo,
                ];
            }
        }

        $params = [
            'modelId' => (string) $requisicao['model'],
            'system' => $sistema,
            'messages' => $mensagens,
            'inferenceConfig' => ['maxTokens' => (int) ($requisicao['maxTokens'] ?? config('ia.max_tokens'))],
        ];

        $ferramentas = [];
        foreach ($requisicao['tools'] ?? [] as $ferramenta) {
            $ferramentas[] = ['toolSpec' => [
                'name' => $ferramenta['name'],
                'description' => $ferramenta['description'],
                'inputSchema' => ['json' => $ferramenta['input_schema']],
            ]];
        }
        if ($ferramentas !== []) {
            $config = ['tools' => $ferramentas];
            if (($requisicao['toolChoice']['type'] ?? null) === 'none') {
                $config['toolChoice'] = ['none' => new \stdClass];
            }
            $params['toolConfig'] = $config;
        }

        return $params;
    }

    /**
     * Junta os eventos do `converseStream` no formato de bloco da Anthropic.
     *
     * @param  iterable<array<string, mixed>>  $eventos
     */
    public function acumular(iterable $eventos, callable $aoEscrever, string $modelo): RespostaClaude
    {
        /** @var array<int, array<string, mixed>> $blocos */
        $blocos = [];
        $parada = null;
        $entrada = 0;
        $saida = 0;
        $cacheLeitura = 0;
        $cacheEscrita = 0;

        foreach ($eventos as $evento) {
            $inicio = $evento['contentBlockStart']['start']['toolUse'] ?? null;
            if (is_array($inicio)) {
                $indice = (int) $evento['contentBlockStart']['contentBlockIndex'];
                $blocos[$indice] = [
                    'type' => 'tool_use',
                    'id' => (string) $inicio['toolUseId'],
                    'name' => (string) $inicio['name'],
                    'input_json' => '',
                ];
            }

            $delta = $evento['contentBlockDelta']['delta'] ?? null;
            if (is_array($delta) && isset($delta['text'])) {
                $indice = (int) $evento['contentBlockDelta']['contentBlockIndex'];
                $blocos[$indice]['type'] = 'text';
                $blocos[$indice]['text'] = ($blocos[$indice]['text'] ?? '').$delta['text'];
                $aoEscrever((string) $delta['text']);
            }
            if (is_array($delta) && isset($delta['toolUse']['input'])) {
                $indice = (int) $evento['contentBlockDelta']['contentBlockIndex'];
                $blocos[$indice]['input_json'] = ($blocos[$indice]['input_json'] ?? '').$delta['toolUse']['input'];
            }

            if (isset($evento['messageStop']['stopReason'])) {
                $parada = (string) $evento['messageStop']['stopReason'];
            }

            $uso = $evento['metadata']['usage'] ?? null;
            if (is_array($uso)) {
                $entrada = (int) ($uso['inputTokens'] ?? 0);
                $saida = (int) ($uso['outputTokens'] ?? 0);
                $cacheLeitura = (int) ($uso['cacheReadInputTokens'] ?? 0);
                $cacheEscrita = (int) ($uso['cacheWriteInputTokens'] ?? 0);
            }
        }

        ksort($blocos);
        $conteudo = [];
        foreach ($blocos as $bloco) {
            if (($bloco['type'] ?? '') === 'tool_use') {
                $entradaJson = json_decode($bloco['input_json'] !== '' ? $bloco['input_json'] : '{}', true);
                $conteudo[] = [
                    'type' => 'tool_use',
                    'id' => $bloco['id'],
                    'name' => $bloco['name'],
                    'input' => is_array($entradaJson) ? $entradaJson : [],
                ];
            } elseif (($bloco['type'] ?? '') === 'text' && ($bloco['text'] ?? '') !== '') {
                $conteudo[] = ['type' => 'text', 'text' => $bloco['text']];
            }
        }

        return new RespostaClaude(
            conteudo: $conteudo,
            stopReason: match ($parada) {
                'tool_use' => 'tool_use',
                'max_tokens' => 'max_tokens',
                'content_filtered', 'guardrail_intervened' => 'refusal',
                default => 'end_turn',
            },
            modelo: $modelo,
            tokensEntrada: $entrada,
            tokensSaida: $saida,
            tokensCacheLeitura: $cacheLeitura,
            tokensCacheEscrita: $cacheEscrita,
        );
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @return list<array<string, mixed>>
     */
    private function conteudo(array $mensagem): array
    {
        $content = $mensagem['content'] ?? '';
        if (is_string($content)) {
            return $content === '' ? [] : [['text' => $content]];
        }

        $saida = [];
        foreach ($content as $bloco) {
            match ($bloco['type'] ?? null) {
                'text' => ($bloco['text'] ?? '') !== '' ? $saida[] = ['text' => $bloco['text']] : null,
                'tool_use' => $saida[] = ['toolUse' => [
                    'toolUseId' => $bloco['id'],
                    'name' => $bloco['name'],
                    'input' => $this->documento($bloco['input'] ?? []),
                ]],
                'tool_result' => $saida[] = ['toolResult' => [
                    'toolUseId' => $bloco['tool_use_id'],
                    'content' => [['text' => is_string($bloco['content']) ? $bloco['content'] : json_encode($bloco['content'], JSON_UNESCAPED_UNICODE)]],
                    'status' => ($bloco['is_error'] ?? false) ? 'error' : 'success',
                ]],
                default => null,
            };
        }

        return $saida;
    }

    private function documento(mixed $entrada): mixed
    {
        if ($entrada instanceof \stdClass) {
            return $entrada;
        }
        if (! is_array($entrada) || $entrada === [] || array_is_list($entrada)) {
            return new \stdClass;
        }

        return $entrada;
    }
}
