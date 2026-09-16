<?php

namespace App\Services\PowerBi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pede ao Power BI que atualize o dataset. Único lugar do projeto que conhece as URLs
 * do Entra ID e da API do Power BI.
 *
 * Duas chamadas: token client-credentials do Entra ID (service principal), e
 * `POST .../datasets/{id}/refreshes`, que responde 202 e roda o refresh em segundo
 * plano no Serviço — o CRM não espera ele terminar.
 *
 * ⚠️ Esta classe NÃO conhece a cota. Quem decide se pode disparar é a
 * `TravaDeRefreshPowerBi`, chamada pelo job. Separado de propósito: a trava é regra de
 * negócio nossa, o refresher é só o transporte.
 */
class PowerBiRefresher
{
    private const ESCOPO = 'https://analysis.windows.net/powerbi/api/.default';

    private const CHAVE_TOKEN = 'powerbi:token';

    /**
     * @return string o `RequestId` do refresh (vazio se o Power BI não mandar), para
     *                procurar no histórico do dataset
     */
    public function disparar(): string
    {
        $cfg = config('powerbi.refresh');

        foreach (['tenant_id', 'client_id', 'client_secret', 'workspace_id', 'dataset_id'] as $chave) {
            if (($cfg[$chave] ?? '') === '') {
                throw new PowerBiRecusadoException("Configuração do Power BI incompleta: falta {$chave} no .env.");
            }
        }

        $url = sprintf(
            'https://api.powerbi.com/v1.0/myorg/groups/%s/datasets/%s/refreshes',
            rawurlencode($cfg['workspace_id']),
            rawurlencode($cfg['dataset_id']),
        );

        $resposta = $this->enviar(fn () => Http::withToken($this->token())
            ->timeout($cfg['timeout'])
            ->acceptJson()
            ->asJson()
            // O Pro não aceita notificação por e-mail em refresh por API com service
            // principal; NoNotification é o único valor que funciona nos dois casos.
            ->post($url, ['notifyOption' => 'NoNotification']));

        if ($resposta->status() === 401) {
            // Token cacheado pode ter sido revogado: o próximo disparo pede outro.
            Cache::forget(self::CHAVE_TOKEN);
        }

        if ($resposta->status() !== 202) {
            throw new PowerBiRecusadoException($this->mensagem($resposta, 'O Power BI recusou o refresh'));
        }

        return (string) ($resposta->header('RequestId') ?: $resposta->header('x-ms-request-id'));
    }

    /**
     * Token do service principal, cacheado até pouco antes de expirar (~1 h). Com até 6
     * disparos por dia seria barato pedir sempre, mas o cache evita que uma retentativa
     * em sequência bata no Entra ID à toa.
     */
    private function token(): string
    {
        $cacheado = Cache::get(self::CHAVE_TOKEN);

        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        $cfg = config('powerbi.refresh');

        $resposta = $this->enviar(fn () => Http::asForm()
            ->timeout($cfg['timeout'])
            ->acceptJson()
            ->post(
                'https://login.microsoftonline.com/'.rawurlencode($cfg['tenant_id']).'/oauth2/v2.0/token',
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $cfg['client_id'],
                    'client_secret' => $cfg['client_secret'],
                    'scope' => self::ESCOPO,
                ],
            ));

        $token = (string) $resposta->json('access_token');

        if (! $resposta->successful() || $token === '') {
            throw new PowerBiRecusadoException($this->mensagem($resposta, 'O Entra ID recusou a credencial do Power BI'));
        }

        // Com TTL sempre — Cache::forever é proibido neste projeto (Redis volatile-lru).
        $segundos = max(60, (int) $resposta->json('expires_in', 3600) - 120);
        Cache::put(self::CHAVE_TOKEN, $token, $segundos);

        return $token;
    }

    /**
     * Rede e 5xx viram `PowerBiIndisponivelException` (retentável); o resto volta para
     * quem chamou decidir.
     *
     * @param  callable(): Response  $chamada
     */
    private function enviar(callable $chamada): Response
    {
        try {
            $resposta = $chamada();
        } catch (ConnectionException $e) {
            throw new PowerBiIndisponivelException('O Power BI não respondeu. A tentativa será repetida.', previous: $e);
        }

        if ($resposta->serverError()) {
            throw new PowerBiIndisponivelException("O Power BI respondeu com erro interno (HTTP {$resposta->status()}). A tentativa será repetida.");
        }

        return $resposta;
    }

    /**
     * ⚠️ Nunca inclui o corpo da requisição: o do token leva o `client_secret`, e esta
     * mensagem vai parar em `totvs_importacoes.passos`, visível na tela.
     */
    private function mensagem(Response $resposta, string $prefixo): string
    {
        $detalhe = $resposta->json('error.message')
            ?? $resposta->json('error_description')
            ?? $resposta->json('error.code')
            ?? $resposta->json('error');

        $texto = "{$prefixo} (HTTP {$resposta->status()})";

        return is_string($detalhe) && $detalhe !== '' ? $texto.': '.mb_substr($detalhe, 0, 300) : $texto.'.';
    }
}
