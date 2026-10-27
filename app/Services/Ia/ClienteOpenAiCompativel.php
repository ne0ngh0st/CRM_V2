<?php

namespace App\Services\Ia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Modelo local (Ollama) ou qualquer servidor "compatível com OpenAI", SÓ PARA DESENVOLVIMENTO.
 *
 * Existe para testar o assistente com um modelo de verdade sem chave da Anthropic
 * (decisão de 2026-10-26: Qwen 2.5 7B no Ollama, na máquina do dev). Não é caminho de
 * produção, e o AppServiceProvider recusa este provedor em produção: um modelo pequeno
 * erra mais na escolha das ferramentas, e apontar isto para um serviço de terceiros
 * mandaria dado de cliente para fora sem o OK que a Anthropic tem.
 *
 * ⚠️ O resto do sistema continua falando o formato da ANTHROPIC — inclusive o histórico
 * gravado em `ia_mensagens.conteudo_api`. Esta classe traduz na ida e na volta; nada fora
 * dela sabe que o modelo é outro. Trocar para a chave real é mudar `IA_PROVEDOR`.
 *
 * Tradução:
 * - `system` (blocos)            → uma mensagem `system`
 * - bloco `tool_use` (assistente) → `tool_calls` da mensagem do assistente
 * - bloco `tool_result` (usuário) → uma mensagem `role: tool` por resultado
 * - bloco `thinking`              → descartado (modelo local não tem)
 * - `finish_reason: tool_calls`   → `stop_reason: tool_use`; `length` → `max_tokens`
 */
final class ClienteOpenAiCompativel implements ClienteClaude
{
    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude
    {
        $corpo = $this->corpo($requisicao);

        $resposta = Http::withToken((string) config('ia.local.chave') ?: 'sem-chave')
            ->timeout((int) config('ia.local.timeout'))
            ->withOptions(['stream' => true])
            ->post(rtrim((string) config('ia.local.url'), '/').'/chat/completions', $corpo);

        if ($resposta->failed()) {
            throw new RuntimeException('Modelo local respondeu '.$resposta->status().': '.mb_substr($resposta->body(), 0, 300));
        }

        return $this->lerStream($resposta->toPsrResponse()->getBody(), $aoEscrever);
    }

    /**
     * A requisição no formato OpenAI. Público para o teste conferir a tradução sem rede.
     *
     * @param  array<string, mixed>  $requisicao
     * @return array<string, mixed>
     */
    public function corpo(array $requisicao): array
    {
        $sistema = implode("\n\n", array_map(
            fn ($b) => is_array($b) ? (string) ($b['text'] ?? '') : (string) $b,
            is_array($requisicao['system'] ?? null) ? $requisicao['system'] : [$requisicao['system'] ?? ''],
        ));

        $mensagens = [['role' => 'system', 'content' => $sistema]];
        foreach ($requisicao['messages'] as $m) {
            array_push($mensagens, ...$this->traduzirMensagem($m));
        }

        $corpo = [
            'model' => (string) config('ia.local.modelo'),
            'messages' => $mensagens,
            'tools' => array_map(fn (array $t) => [
                'type' => 'function',
                'function' => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'parameters' => $t['input_schema'],
                ],
            ], $requisicao['tools'] ?? []),
            'max_tokens' => (int) ($requisicao['maxTokens'] ?? 2000),
            'temperature' => 0.2,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if (($requisicao['toolChoice']['type'] ?? null) === 'none') {
            $corpo['tool_choice'] = 'none';
        }

        return $corpo;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<array<string, mixed>>
     */
    private function traduzirMensagem(array $m): array
    {
        if (is_string($m['content'])) {
            return [['role' => $m['role'], 'content' => $m['content']]];
        }

        if ($m['role'] === 'assistant') {
            $texto = [];
            $chamadas = [];
            foreach ($m['content'] as $b) {
                match ($b['type'] ?? null) {
                    'text' => $texto[] = $b['text'],
                    'tool_use' => $chamadas[] = [
                        'id' => $b['id'],
                        'type' => 'function',
                        'function' => ['name' => $b['name'], 'arguments' => json_encode($b['input'] ?: new \stdClass, JSON_UNESCAPED_UNICODE)],
                    ],
                    default => null, // thinking, fallback… não existem do outro lado
                };
            }

            return [array_filter([
                'role' => 'assistant',
                'content' => implode("\n\n", $texto),
                'tool_calls' => $chamadas ?: null,
            ], fn ($v) => $v !== null)];
        }

        // Usuário: texto vira uma mensagem; cada tool_result vira uma mensagem `tool`.
        $saida = [];
        $texto = [];
        foreach ($m['content'] as $b) {
            if (($b['type'] ?? null) === 'tool_result') {
                $saida[] = ['role' => 'tool', 'tool_call_id' => $b['tool_use_id'], 'content' => (string) $b['content']];
            } elseif (($b['type'] ?? null) === 'text') {
                $texto[] = $b['text'];
            }
        }
        if ($texto !== []) {
            $saida[] = ['role' => 'user', 'content' => implode("\n\n", $texto)];
        }

        return $saida;
    }

    /**
     * Lê o stream SSE (`data: {...}` por linha, `data: [DONE]` no fim) e monta a resposta
     * no formato da Anthropic. Público para o teste alimentar um stream gravado.
     */
    public function lerStream(\Psr\Http\Message\StreamInterface $stream, callable $aoEscrever): RespostaClaude
    {
        $texto = '';
        $chamadas = [];
        $fim = null;
        $uso = ['entrada' => 0, 'saida' => 0];
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(1024);

            while (($quebra = strpos($buffer, "\n")) !== false) {
                $linha = trim(substr($buffer, 0, $quebra));
                $buffer = substr($buffer, $quebra + 1);

                if (! str_starts_with($linha, 'data:')) {
                    continue;
                }
                $dado = trim(substr($linha, 5));
                if ($dado === '[DONE]') {
                    break 2;
                }

                $pedaco = json_decode($dado, true);
                if (! is_array($pedaco)) {
                    continue;
                }

                if (isset($pedaco['usage'])) {
                    $uso['entrada'] = (int) ($pedaco['usage']['prompt_tokens'] ?? 0);
                    $uso['saida'] = (int) ($pedaco['usage']['completion_tokens'] ?? 0);
                }

                $escolha = $pedaco['choices'][0] ?? null;
                if ($escolha === null) {
                    continue;
                }

                $delta = $escolha['delta'] ?? [];
                if (($delta['content'] ?? '') !== '') {
                    $texto .= $delta['content'];
                    $aoEscrever($delta['content']);
                }

                // As chamadas chegam em pedaços indexados (OpenAI) ou inteiras (Ollama).
                foreach ($delta['tool_calls'] ?? [] as $c) {
                    $i = $c['index'] ?? count($chamadas);
                    $chamadas[$i] ??= ['id' => null, 'nome' => '', 'args' => ''];
                    $chamadas[$i]['id'] ??= $c['id'] ?? null;
                    $chamadas[$i]['nome'] .= $c['function']['name'] ?? '';
                    $chamadas[$i]['args'] .= $c['function']['arguments'] ?? '';
                }

                $fim = $escolha['finish_reason'] ?? $fim;
            }
        }

        $conteudo = [];
        if (trim($texto) !== '') {
            $conteudo[] = ['type' => 'text', 'text' => $texto];
        }
        foreach ($chamadas as $c) {
            $entrada = json_decode($c['args'] ?: '{}', true);
            $conteudo[] = [
                'type' => 'tool_use',
                'id' => $c['id'] ?: 'call_'.Str::lower(Str::random(12)),
                'name' => $c['nome'],
                'input' => is_array($entrada) && $entrada !== [] ? $entrada : new \stdClass,
            ];
        }

        return new RespostaClaude(
            conteudo: $conteudo,
            stopReason: match (true) {
                $chamadas !== [] => 'tool_use',
                $fim === 'length' => 'max_tokens',
                default => 'end_turn',
            },
            modelo: 'local:'.config('ia.local.modelo'),
            tokensEntrada: $uso['entrada'],
            tokensSaida: $uso['saida'],
        );
    }
}
