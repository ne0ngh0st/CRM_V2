<?php

namespace App\Http\Controllers;

use App\Models\Orcamento;
use App\Services\Portal\GeradorDePedidoNoPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * O SIC avisa que um pedido criado pelo CRM mudou de status (aprovado/recusado pela
 * Laís). Combinado com o Philipe em 2026-10-20 — a API deles não tem consulta, e a
 * recusa nunca chega ao TOTVS: sem isto ela não aparecia em lugar nenhum.
 *
 * Corpo: `{"orderId": 4512, "status": "REJECTED", "reason": "…"}`. `orderReference`
 * ("ORC-77", que nós mandamos na criação) serve de alternativa ao `orderId`.
 *
 * Pedido que o CRM não conhece responde 200 com `ignorado`: pedido digitado direto no
 * SIC também pode disparar o aviso, e erro ali só faria eles retentarem à toa.
 */
class SicPedidoWebhookController extends Controller
{
    public function __invoke(Request $request, GeradorDePedidoNoPortal $gerador): JsonResponse
    {
        $chave = trim((string) config('portal.webhook_token'));

        if ($chave === '') {
            return response()->json(['erro' => 'webhook_nao_configurado'], 503);
        }

        $recebida = trim((string) ($request->bearerToken() ?? $request->header('X-Webhook-Token', '')));

        if ($recebida === '' || ! hash_equals($chave, $recebida)) {
            return response()->json(['erro' => 'nao_autorizado'], 401);
        }

        $entrada = $request->all();
        if (is_string($entrada['status'] ?? null)) {
            $entrada['status'] = strtoupper(trim($entrada['status']));
        }

        /*
         * ⚠️ Validator explícito, não `$request->validate()`: fora do grupo `web`, sem
         * `Accept: application/json`, a falha viraria um 302 para lugar nenhum.
         */
        $validador = Validator::make($entrada, [
            'orderId' => ['required_without:orderReference', 'nullable', 'integer'],
            'orderReference' => ['required_without:orderId', 'nullable', 'string', 'max:50'],
            'status' => ['required', 'string', 'max:40', 'regex:/^[A-Z_]+$/'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($validador->fails()) {
            return response()->json(['erro' => 'payload_invalido', 'detalhes' => $validador->errors()], 422);
        }

        $dados = $validador->validated();

        $orcamento = $this->localizar($dados);

        if ($orcamento === null) {
            Log::info('Webhook do SIC para pedido desconhecido.', $dados);

            return response()->json(['ok' => true, 'ignorado' => 'pedido_desconhecido']);
        }

        $mudou = $gerador->registrarStatus($orcamento, $dados['status'], $dados['reason'] ?? null);

        return response()->json(['ok' => true, 'orcamento' => $orcamento->id, 'atualizado' => $mudou]);
    }

    /** @param  array<string, mixed>  $dados */
    private function localizar(array $dados): ?Orcamento
    {
        if (filled($dados['orderId'] ?? null)) {
            return Orcamento::where('portal_pedido_id', $dados['orderId'])->first();
        }

        if (preg_match('/^ORC-(\d+)$/', (string) ($dados['orderReference'] ?? ''), $m)) {
            return Orcamento::whereKey((int) $m[1])->whereNotNull('portal_pedido_id')->first();
        }

        return null;
    }
}
