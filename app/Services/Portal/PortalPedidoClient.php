<?php

namespace App\Services\Portal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fala com o `POST /v1/api/orders` do Portal Autopel. Único lugar do projeto que
 * conhece a URL, o token e o formato de erro deles.
 *
 * Sondado contra o homolog em 10/09/2026: a ordem de validação é
 * `formato do corpo → createdBy → clientId → clientRepresentativeId → produto`,
 * e erro NUNCA grava nada do lado deles ("não existe pedido pela metade para limpar").
 */
class PortalPedidoClient
{
    /**
     * @param  array<string, mixed>  $corpo
     * @return array{id:int, replay:bool}
     */
    public function criarPedido(array $corpo, string $idempotencyKey): array
    {
        $token = (string) config('portal.token');

        if ($token === '') {
            throw new PortalPedidoInvalidoException(
                'O token do Portal não está configurado neste ambiente.'
            );
        }

        try {
            $resposta = Http::withToken($token)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->timeout((int) config('portal.timeout'))
                ->acceptJson()
                ->asJson()
                ->post(config('portal.base_url').'/v1/api/orders', $corpo);
        } catch (ConnectionException $e) {
            /*
             * A resposta não chegou. Pode ser que o pedido tenha entrado — e é
             * exatamente para isso que a chave existe: reenviar a requisição idêntica
             * devolve o id dele com `Idempotent-Replay: true`, sem duplicar.
             */
            throw new PortalIndisponivelException(
                'O Portal não respondeu. A tentativa será repetida com a mesma chave.',
                previous: $e
            );
        }

        if ($resposta->successful()) {
            $id = $resposta->json('payload.id');

            if (! is_int($id) && ! ctype_digit((string) $id)) {
                throw new PortalPedidoRecusadoException(
                    'O Portal respondeu sem o id do pedido. Confira antes de reenviar.'
                );
            }

            return [
                'id' => (int) $id,
                /*
                 * `true` = o pedido já existia e esta chamada só o devolveu. Não é
                 * erro; é a idempotência funcionando depois de uma retentativa.
                 */
                'replay' => filter_var($resposta->header('Idempotent-Replay'), FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if ($resposta->serverError()) {
            throw new PortalIndisponivelException(
                'O Portal respondeu com erro interno. A tentativa será repetida com a mesma chave.'
            );
        }

        throw new PortalPedidoRecusadoException($this->mensagemDeErro($resposta->json(), $resposta->status()));
    }

    /**
     * ⚠️ O `401` tem formato PRÓPRIO e mais enxuto que os outros erros — é gerado antes
     * do processamento. Tratar tudo como o formato "completo" faria a mensagem sumir
     * justamente no caso de token revogado.
     *
     * @param  mixed  $corpo
     */
    private function mensagemDeErro($corpo, int $status): string
    {
        if (! is_array($corpo)) {
            return "O Portal recusou o pedido (HTTP {$status}).";
        }

        $mensagem = (string) ($corpo['message'] ?? "O Portal recusou o pedido (HTTP {$status}).");

        /* Erros de formato trazem a lista de campos em `errors` — é o que diz o que corrigir. */
        if (! empty($corpo['errors']) && is_array($corpo['errors'])) {
            $mensagem .= ': '.implode('; ', array_map('strval', $corpo['errors']));
        }

        return $mensagem;
    }
}
