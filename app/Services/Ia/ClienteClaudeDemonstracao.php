<?php

namespace App\Services\Ia;

/**
 * Um "modelo" de mentira para desenvolver a tela sem chave da API.
 *
 * ⚠️ SÓ ENTRA EM AMBIENTE `local` E SEM `ANTHROPIC_API_KEY` (ver o AppServiceProvider).
 * Em produção, sem chave, o assistente falha de verdade — o que é o certo: resposta de
 * demonstração em produção seria pior que erro.
 *
 * Exercita o caminho inteiro — streaming, chamada de ferramenta REAL contra o banco,
 * Reverb e polling — com uma regra boba: número de 5+ dígitos vira consulta de pedido,
 * "meta" vira consulta de meta, "atualiz" vira frescor dos dados.
 */
final class ClienteClaudeDemonstracao implements ClienteClaude
{
    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude
    {
        $mensagens = $requisicao['messages'];
        $ultima = end($mensagens);
        $conteudo = $ultima['content'];

        // Voltou de uma ferramenta: "resume" o resultado.
        if (is_array($conteudo) && ($conteudo[0]['type'] ?? null) === 'tool_result') {
            $dados = json_decode((string) $conteudo[0]['content'], true);
            $texto = "**Modo demonstração** (sem chave da API). A ferramenta respondeu:\n\n"
                .$this->lista($dados ?? ['resultado' => $conteudo[0]['content']]);

            return $this->escrever($texto, $aoEscrever);
        }

        $pergunta = mb_strtolower(is_array($conteudo) ? (string) ($conteudo[0]['text'] ?? '') : (string) $conteudo);
        $pergunta = preg_replace('/<contexto>.*<\/contexto>/s', '', $pergunta);
        $id = 'toolu_demo_'.substr(md5((string) microtime(true)), 0, 8);

        if (($requisicao['toolChoice']['type'] ?? null) !== 'none') {
            if (preg_match('/\b([a-z]?\d{5,})\b/', $pergunta, $m)) {
                return $this->ferramenta($id, 'consultar_pedido', ['numero' => $m[1]]);
            }
            if (str_contains($pergunta, 'meta')) {
                return $this->ferramenta($id, 'minha_meta', ['ano' => null, 'mes' => null]);
            }
            if (str_contains($pergunta, 'atualiz')) {
                return $this->ferramenta($id, 'frescor_dos_dados', []);
            }
        }

        return $this->escrever(
            "**Modo demonstração**: não há `ANTHROPIC_API_KEY` neste ambiente, então quem responde é um roteiro fixo.\n\n"
            ."Experimente:\n- um número de pedido (ex.: *status do pedido 992086*)\n- *quanto falta para a minha meta?*\n- *os dados estão atualizados?*",
            $aoEscrever,
        );
    }

    private function ferramenta(string $id, string $nome, array $entrada): RespostaClaude
    {
        usleep(400_000);

        return new RespostaClaude(
            conteudo: [['type' => 'tool_use', 'id' => $id, 'name' => $nome, 'input' => $entrada === [] ? new \stdClass : $entrada]],
            stopReason: 'tool_use',
            modelo: 'demonstracao',
        );
    }

    private function escrever(string $texto, callable $aoEscrever): RespostaClaude
    {
        // Em pedaços, com pausa, para a tela mostrar o texto chegando como no real.
        foreach (preg_split('/(?<=\s)/u', $texto) as $pedaco) {
            $aoEscrever($pedaco);
            usleep(25_000);
        }

        return new RespostaClaude(conteudo: [['type' => 'text', 'text' => $texto]], stopReason: 'end_turn', modelo: 'demonstracao');
    }

    private function lista(array $dados, int $nivel = 0): string
    {
        $linhas = [];
        foreach ($dados as $chave => $valor) {
            $recuo = str_repeat('  ', $nivel);
            if (is_array($valor)) {
                $linhas[] = "{$recuo}- **{$chave}**:";
                $linhas[] = $this->lista(array_slice($valor, 0, 8, true), $nivel + 1);
            } else {
                $linhas[] = "{$recuo}- **{$chave}**: ".(is_bool($valor) ? ($valor ? 'sim' : 'não') : ($valor ?? '—'));
            }
        }

        return implode("\n", $linhas);
    }
}
