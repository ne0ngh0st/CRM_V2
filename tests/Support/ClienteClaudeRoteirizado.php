<?php

namespace Tests\Support;

use App\Services\Ia\ClienteClaude;
use App\Services\Ia\RespostaClaude;
use RuntimeException;

/**
 * A API da Anthropic de mentira: devolve respostas pré-escritas, na ordem, e guarda as
 * requisições que recebeu para o teste conferir o que foi enviado.
 *
 * A suíte nunca chama a API de verdade — custaria dinheiro e dependeria de rede.
 */
final class ClienteClaudeRoteirizado implements ClienteClaude
{
    /** @var list<array<string, mixed>> */
    public array $requisicoes = [];

    /**
     * As mesmas requisições como JSON — o único jeito de ver a diferença entre `{}` e
     * `[]`, que o array do PHP apaga.
     *
     * @var list<string>
     */
    public array $requisicoesJson = [];

    /** @var list<RespostaClaude|\Throwable|callable> */
    private array $roteiro;

    /** @param list<RespostaClaude|\Throwable|callable> $roteiro */
    public function __construct(array $roteiro)
    {
        $this->roteiro = $roteiro;
    }

    public function responder(array $requisicao, callable $aoEscrever): RespostaClaude
    {
        // Ida e volta por JSON: o que o teste confere é o que iria pelo fio.
        $this->requisicoesJson[] = json_encode($requisicao, JSON_UNESCAPED_UNICODE);
        $this->requisicoes[] = json_decode(end($this->requisicoesJson), true);

        $passo = array_shift($this->roteiro) ?? throw new RuntimeException('Roteiro acabou: o orquestrador chamou a API mais vezes que o esperado.');

        if ($passo instanceof \Throwable) {
            throw $passo;
        }

        // Passo-função escreve o próprio texto, aos pedaços se quiser (é como o teste
        // simula o usuário apertando "Parar" no meio da escrita).
        if (is_callable($passo)) {
            return $passo($requisicao, $aoEscrever);
        }

        $resposta = $passo;
        $texto = $resposta->texto();
        if ($texto !== '') {
            $aoEscrever($texto);
        }

        return $resposta;
    }

    public static function texto(string $texto, string $stop = 'end_turn'): RespostaClaude
    {
        return new RespostaClaude(
            conteudo: [['type' => 'text', 'text' => $texto]],
            stopReason: $stop,
            modelo: 'claude-opus-5-5',
            tokensEntrada: 100,
            tokensSaida: 20,
            tokensCacheLeitura: 1000,
        );
    }

    /** @param array<string, mixed> $entrada */
    public static function ferramenta(string $nome, array $entrada, string $id = 'toolu_1'): RespostaClaude
    {
        return new RespostaClaude(
            conteudo: [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'assinatura-'.$id],
                ['type' => 'tool_use', 'id' => $id, 'name' => $nome, 'input' => $entrada],
            ],
            stopReason: 'tool_use',
            modelo: 'claude-opus-5-5',
            tokensEntrada: 100,
            tokensSaida: 30,
        );
    }
}
