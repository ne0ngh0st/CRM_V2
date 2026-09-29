<?php

namespace App\Http\Controllers;

use App\Services\Busca\BuscaCruzada;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contagem da outra aba da Carteira. Devolve só um número.
 *
 * Desde 2026-09-29 clientes e leads moram na mesma página. O botão da aba que não está
 * aberta mostra "Leads (15)" perguntando aqui — fora da primeira pintura, uma vez por
 * termo. O número tem que ser o total que aquela aba mostra ao ser aberta.
 *
 * ⚠️ O escopo sai do MESMO resolver e dos MESMOS parâmetros (`visao_supervisor`,
 * `visao_vendedor`) que a página usa — o contador nunca pode contar algo que a aba não
 * vá mostrar, nem vazar o tamanho da carteira de outra pessoa.
 */
class BuscaCruzadaController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly BuscaCruzada $busca,
    ) {
    }

    public function __invoke(Request $request, string $alvo): JsonResponse
    {
        $termo = trim((string) $request->string('busca'));

        if (mb_strlen($termo) < BuscaCruzada::MINIMO_CARACTERES) {
            return response()->json(['total' => 0]);
        }

        $codVendedores = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        )['codVendedores'];

        $total = $alvo === 'clientes'
            ? $this->busca->contarClientes($codVendedores, $termo)
            : $this->busca->contarLeads($codVendedores, $termo);

        return response()->json(['total' => $total]);
    }
}
