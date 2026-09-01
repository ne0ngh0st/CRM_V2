<?php

namespace App\Http\Controllers;

use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * POST /webhooks/wordpress-leads — captura bruta do form do site.
 *
 * Fora do grupo `web` de propósito: o WordPress posta server-side, sem CSRF
 * nem sessão. A autenticação é um segredo compartilhado.
 *
 * ⚠️ O SEGREDO VIAJA NA QUERY STRING (`?token=…`), e isso não foi preguiça.
 * O plugin de webhook do site tem UM campo — a URL. Não existe onde pôr
 * header. Header continua aceito (Bearer / X-Webhook-Token) para o dia em que
 * a origem mudar, mas a URL é o caminho que funciona hoje.
 *
 * Consequência assumida: o token aparece nos access logs do nginx e do ALB.
 * O estrago de um vazamento é limitado de propósito — este endpoint só
 * INSERE captura, não lê, não atualiza e não apaga nada. Rotacionar é trocar
 * o valor em dois lugares (o .env e o campo do plugin).
 */
class WordpressLeadWebhookController extends Controller
{
    /**
     * Um form de contato não passa de alguns KB. O teto existe para que um
     * token vazado não vire enchimento de disco num longText.
     */
    private const MAX_BYTES = 262144; // 256 KB

    public function __construct(
        private readonly WpLeadIngestor $ingestor,
    ) {
    }

    public function __invoke(Request $request): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            // Sem `Access-Control-Allow-Origin` de propósito (travado por teste):
            // quem posta aqui é servidor. Sem esse header, nenhum site consegue
            // usar o browser de um terceiro para bater neste endpoint.
            return response('', HttpResponse::HTTP_NO_CONTENT)
                ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Webhook-Token');
        }

        if (! $request->isMethod('POST')) {
            return $this->erro(HttpResponse::HTTP_METHOD_NOT_ALLOWED, 'method_not_allowed');
        }

        $secret = trim((string) config('marketing.wp_webhook_secret'));
        if ($secret === '') {
            return $this->erro(HttpResponse::HTTP_SERVICE_UNAVAILABLE, 'webhook_not_configured');
        }

        if (! $this->autenticado($request, $secret)) {
            return $this->erro(HttpResponse::HTTP_UNAUTHORIZED, 'unauthorized');
        }

        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return $this->erro(HttpResponse::HTTP_REQUEST_ENTITY_TOO_LARGE, 'payload_too_large');
        }

        try {
            $resultado = $this->ingestor->ingerirDoWebhook($request);
        } catch (Throwable $e) {
            // Último recurso: se nem a staging entrou (banco fora do ar), o
            // corpo vai para o log. O WordPress não reenvia — sem isto o lead
            // do cliente não existiria em lugar nenhum.
            Log::error('wp-lead: captura perdida, banco indisponível', [
                'erro' => $e->getMessage(),
                'content_type' => $request->header('Content-Type'),
                'payload' => mb_strcut($request->getContent(), 0, 8000),
            ]);
            report($e);

            return $this->erro(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, 'insert_failed');
        }

        $staging = $resultado['staging'];

        // Reenvio do mesmo POST devolve 200 com o mesmo id, não um segundo lead.
        return $this->json(
            ['ok' => true, 'staging_id' => $staging->id, 'duplicada' => $resultado['duplicada']],
            $resultado['duplicada'] ? HttpResponse::HTTP_OK : HttpResponse::HTTP_CREATED,
        );
    }

    /**
     * Ordem: header primeiro (mais seguro, não vai para log), query depois
     * (o que o plugin do site consegue mandar). `hash_equals` nos dois para
     * não vazar o segredo por tempo de resposta.
     */
    private function autenticado(Request $request, string $secret): bool
    {
        $candidatos = [
            $request->bearerToken() ?? '',
            (string) $request->header('X-Webhook-Token', ''),
            (string) $request->query('token', ''),
        ];

        foreach ($candidatos as $candidato) {
            $candidato = trim($candidato);
            if ($candidato !== '' && hash_equals($secret, $candidato)) {
                return true;
            }
        }

        return false;
    }

    private function erro(int $status, string $error): JsonResponse
    {
        return $this->json(['ok' => false, 'error' => $error], $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Content-Type', 'application/json; charset=utf-8');
    }
}
