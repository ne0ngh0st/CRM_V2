<?php

namespace App\Services\Ia;

use App\Events\IaEvento;
use App\Models\IaMensagem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * O loop de uma resposta: pergunta → modelo → ferramentas → modelo… → texto final.
 *
 * Roda no job `ResponderMensagemIaJob` (fila `ia`), nunca na requisição: uma resposta
 * com duas ou três consultas leva de 5 a 20 s (Regra de ouro nº 9).
 *
 * ⚠️ HISTÓRICO SÓ POR ANEXAÇÃO. A conversa enviada à API é a concatenação dos
 * `conteudo_api` das mensagens anteriores, sem nenhuma edição — nem "limpar" bloco de
 * raciocínio, nem resumir, nem reordenar. O Claude Opus 5.5 confere que o histórico não
 * mudou e recusa conversa editada; e o cache de prompt também depende disso.
 */
final class Orquestrador
{
    /** Intervalo mínimo entre dois eventos `delta` (s). Mais que isso é ruído na rede. */
    private const INTERVALO_DELTA = 0.15;

    /** Intervalo mínimo entre duas conferências do "Parar" durante o texto (s). */
    private const INTERVALO_CHECAGEM_PARADA = 0.5;

    /** Modelos que aceitam o `fallbacks: "default"` do servidor contra recusa. */
    private const MODELOS_COM_FALLBACK = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    public function __construct(
        private readonly ClienteClaude $claude,
        private readonly CatalogoDeFerramentas $ferramentas,
        private readonly PromptDoSistema $prompt,
        private readonly AcessoIa $acesso,
    ) {}

    public function responder(IaMensagem $resposta, ?string $modelo = null): void
    {
        $inicio = microtime(true);
        $conversa = $resposta->conversa;
        $user = $conversa->user;
        $modelo ??= (string) config('ia.modelo');

        $pergunta = IaMensagem::query()
            ->where('conversa_id', $conversa->id)
            ->where('id', '<', $resposta->id)
            ->where('papel', 'usuario')
            ->orderByDesc('id')
            ->first();

        $contexto = new ContextoIa($user, $conversa, $pergunta?->tela);
        $historico = $this->historico($resposta);

        $novos = [];
        $chamadas = [];
        $uso = ['entrada' => 0, 'saida' => 0, 'cache_leitura' => 0, 'cache_escrita' => 0, 'custo' => 0.0];
        $textoAoVivo = '';
        $ultimoDelta = 0.0;
        $textoFinal = [];
        $stopReason = null;
        $maxVoltas = (int) config('ia.max_voltas');

        $ultimaChecagem = 0.0;

        for ($volta = 1; $volta <= $maxVoltas; $volta++) {
            $this->pararSePedido($resposta);

            $requisicao = $this->requisicao($modelo, [...$historico, ...$novos], ultimaVolta: $volta === $maxVoltas);

            if ($textoAoVivo !== '') {
                $textoAoVivo .= "\n\n";
            }

            $r = $this->claude->responder($requisicao, function (string $pedaco) use (&$textoAoVivo, &$ultimoDelta, &$ultimaChecagem, $user, $resposta) {
                $textoAoVivo .= $pedaco;
                $agora = microtime(true);

                // "Parar" no meio do texto: conferido a cada meio segundo, não a cada
                // pedaço — são dezenas por segundo, e cada conferência é uma ida ao Redis.
                if ($agora - $ultimaChecagem >= self::INTERVALO_CHECAGEM_PARADA) {
                    $ultimaChecagem = $agora;
                    $this->pararSePedido($resposta);
                }

                if ($agora - $ultimoDelta >= self::INTERVALO_DELTA) {
                    $ultimoDelta = $agora;
                    $this->transmitir($user->id, $resposta->id, 'delta', ['texto' => ltrim($textoAoVivo)]);
                }
            });

            $novos[] = ['role' => 'assistant', 'content' => $this->normalizarBlocos($r->conteudo)];
            $uso['entrada'] += $r->tokensEntrada;
            $uso['saida'] += $r->tokensSaida;
            $uso['cache_leitura'] += $r->tokensCacheLeitura;
            $uso['cache_escrita'] += $r->tokensCacheEscrita;
            $uso['custo'] += $this->acesso->custo($r->modelo ?: $modelo, $r->tokensEntrada, $r->tokensSaida, $r->tokensCacheLeitura, $r->tokensCacheEscrita);
            $stopReason = $r->stopReason;

            if ($r->texto() !== '') {
                $textoFinal[] = $r->texto();
            }

            if ($r->stopReason !== 'tool_use') {
                break;
            }

            // Todas as chamadas da volta numa ÚNICA mensagem de resultados (exigência da
            // API para chamadas em paralelo).
            $resultados = [];
            foreach ($r->chamadasDeFerramenta() as $chamada) {
                $this->transmitir($user->id, $resposta->id, 'ferramenta', ['rotulo' => $this->ferramentas->rotulo($chamada['name'])]);
                $chamadas[] = $chamada['name'];
                $resultados[] = $this->ferramentas->executar($contexto, $chamada);
            }
            $novos[] = ['role' => 'user', 'content' => $resultados];
        }

        $texto = trim(implode("\n\n", $textoFinal));

        if ($stopReason === 'refusal' && $texto === '') {
            $texto = 'Não consigo ajudar com esse pedido. Se for algo do CRM, tente reformular a pergunta.';
        } elseif ($stopReason === 'max_tokens') {
            $texto .= "\n\n_(A resposta ficou longa demais e foi cortada. Peça só a parte que precisa.)_";
        } elseif ($texto === '') {
            $texto = 'Não consegui montar uma resposta. Tente perguntar de outro jeito.';
        }

        /*
         * ⚠️ Gravação CONDICIONAL ao status ainda ser "processando". Se o usuário apertar
         * "Parar" depois da última conferência mas antes desta linha, um `update()` comum
         * desfaria o cancelamento: a resposta descartada voltaria para a tela E para o
         * histórico. Por isso é um UPDATE com a condição no WHERE (atômico), com os campos
         * de array codificados à mão — o update de query não passa pelos casts do model.
         */
        $gravou = IaMensagem::query()
            ->whereKey($resposta->id)
            ->where('status', IaMensagem::STATUS_PROCESSANDO)
            ->update([
                'texto' => $texto,
                'conteudo_api' => json_encode($novos, JSON_UNESCAPED_UNICODE),
                'ferramentas' => json_encode($chamadas),
                'status' => IaMensagem::STATUS_OK,
                'tokens_entrada' => $uso['entrada'],
                'tokens_saida' => $uso['saida'],
                'tokens_cache_leitura' => $uso['cache_leitura'],
                'tokens_cache_escrita' => $uso['cache_escrita'],
                'custo_usd' => round($uso['custo'], 6),
                'latencia_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'updated_at' => now(),
            ]);

        if ($gravou === 0) {
            return; // parada no último instante: a tela já mostra "interrompida"
        }
        $conversa->touch();

        $this->transmitir($user->id, $resposta->id, 'concluida', ['mensagem' => $resposta->fresh()->paraTela()]);
    }

    public function falhou(IaMensagem $resposta, string $erro): void
    {
        // Condicional pelo mesmo motivo da gravação do sucesso: resposta cancelada não
        // vira "erro" porque a conexão cortada ao parar estourou alguma exceção.
        $marcou = IaMensagem::query()
            ->whereKey($resposta->id)
            ->where('status', IaMensagem::STATUS_PROCESSANDO)
            ->update([
                'status' => IaMensagem::STATUS_ERRO,
                'erro' => 'Não consegui responder agora. Tente de novo em instantes.',
                'updated_at' => now(),
            ]);

        if ($marcou === 0) {
            return;
        }
        Log::warning('ia: resposta falhou', ['mensagem' => $resposta->id, 'erro' => mb_substr($erro, 0, 500)]);

        $this->transmitir($resposta->conversa->user_id, $resposta->id, 'erro', ['mensagem' => $resposta->fresh()->paraTela()]);
    }

    /**
     * A conversa até aqui: tudo que deu certo, na ordem, sem edição.
     *
     * Resposta que falhou fica de fora inteira (não tem `conteudo_api`): o histórico
     * continua válido, só com duas perguntas seguidas — a API junta as duas num turno.
     *
     * @return list<array<string, mixed>>
     */
    private function historico(IaMensagem $resposta): array
    {
        $mensagens = IaMensagem::query()
            ->where('conversa_id', $resposta->conversa_id)
            ->where('id', '<', $resposta->id)
            ->where('status', IaMensagem::STATUS_OK)
            ->whereNotNull('conteudo_api')
            ->orderBy('id')
            ->pluck('conteudo_api');

        $historico = [];
        foreach ($mensagens as $parte) {
            foreach ($parte as $mensagem) {
                $historico[] = ['role' => $mensagem['role'], 'content' => is_array($mensagem['content'])
                    ? $this->normalizarBlocos($mensagem['content'])
                    : $mensagem['content']];
            }
        }

        return $historico;
    }

    /**
     * ⚠️ O ÚNICO ajuste permitido nos blocos, e ele não muda o que a API vê: `input` vazio
     * de `tool_use` vira `{}`. No PHP, `{}` decodificado vira `[]`, e `[]` reencodado
     * sairia como lista JSON — a API recusa. É desfazer o artefato do PHP, não editar.
     *
     * @param  list<array<string, mixed>>  $blocos
     * @return list<array<string, mixed>>
     */
    private function normalizarBlocos(array $blocos): array
    {
        return array_map(function (array $b) {
            if (($b['type'] ?? null) === 'tool_use' && ($b['input'] ?? []) === []) {
                $b['input'] = new \stdClass;
            }

            return $b;
        }, $blocos);
    }

    /**
     * @param  list<array<string, mixed>>  $mensagens
     * @return array<string, mixed>
     */
    private function requisicao(string $modelo, array $mensagens, bool $ultimaVolta): array
    {
        $req = [
            'model' => $modelo,
            'maxTokens' => (int) config('ia.max_tokens'),
            'system' => $this->prompt->sistema(),
            'tools' => $this->ferramentas->definicoes(),
            'messages' => $mensagens,
            'outputConfig' => ['effort' => (string) config('ia.effort')],
            // Cache automático do fim da conversa: a pergunta seguinte relê tudo a ~5%.
            'cacheControl' => ['type' => 'ephemeral'],
        ];

        // Na última volta permitida o modelo tem que responder em texto: sem isso, um
        // laço de consultas terminaria com um `tool_use` sem resposta para o usuário.
        if ($ultimaVolta) {
            $req['toolChoice'] = ['type' => 'none'];
        }

        if (in_array($modelo, self::MODELOS_COM_FALLBACK, true)) {
            $req['betas'] = ['server-side-fallback-2026-07-01'];
            $req['fallbacks'] = 'default';
        }

        return $req;
    }

    /** @throws RespostaCancelada */
    private function pararSePedido(IaMensagem $resposta): void
    {
        if (IaMensagem::paradaPedida($resposta->id)) {
            throw new RespostaCancelada;
        }
    }

    /** Transmissão é melhor esforço: Reverb fora do ar não pode derrubar a resposta. */
    private function transmitir(int $userId, int $mensagemId, string $tipo, array $dados): void
    {
        try {
            event(new IaEvento($userId, $mensagemId, $tipo, $dados));
        } catch (Throwable $e) {
            Log::info('ia: transmissão falhou (o polling cobre)', ['erro' => $e->getMessage()]);
        }
    }
}
