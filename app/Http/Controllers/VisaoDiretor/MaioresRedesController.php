<?php

namespace App\Http\Controllers\VisaoDiretor;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Services\VisaoDiretor\Redes\MaioresRedes;
use App\Services\VisaoDiretor\Redes\MaioresRedesResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visão Diretor → Maiores redes: das maiores redes do país (planilha da diretoria 2026),
 * em quais a Autopel atua. Quem entra é o gate do grupo de rotas (`can:ver-visao-diretor`).
 *
 * Leitura no `MaioresRedesResolver`; toda escrita (vincular, remover) no `MaioresRedes`.
 */
class MaioresRedesController extends Controller
{
    use ExportaPlanilha;

    public function __construct(
        private readonly MaioresRedesResolver $resolver,
        private readonly MaioresRedes $redes,
    ) {
    }

    public function index(Request $request): Response
    {
        $filtros = MaioresRedesResolver::filtros($request->query());

        return Inertia::render('VisaoDiretor/MaioresRedes', [
            'resumo' => fn () => $this->resolver->resumo(),
            'lista' => fn () => $filtros['categoria'] === '' ? null : $this->resolver->lista($filtros['categoria'], $filtros),
            'filtros' => $filtros,
        ]);
    }

    /** O recorte da tela (aba e filtros; no Resumo, todas as redes) em Excel. */
    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('maiores-redes', $request);
    }

    /** Os clientes e leads da rede, para a linha aberta. */
    public function vinculos(RedeMercado $rede): JsonResponse
    {
        return response()->json($this->redes->vinculosDa($rede));
    }

    public function buscar(Request $request): JsonResponse
    {
        return response()->json(['resultados' => $this->resolver->buscarParaVincular((string) $request->query('termo'))]);
    }

    public function vincular(Request $request, RedeMercado $rede): JsonResponse
    {
        [$tipo, $codigo] = $this->alvo($request);
        $this->redes->vincular($rede, $tipo, $codigo, $request->user()?->id);

        return response()->json($this->redes->vinculosDa($rede->refresh()) + ['rede' => $this->contadores($rede)]);
    }

    public function remover(Request $request, RedeMercado $rede): JsonResponse
    {
        [$tipo, $codigo] = $this->alvo($request);
        $this->redes->remover($rede, $tipo, $codigo, $request->user()?->id);

        return response()->json($this->redes->vinculosDa($rede->refresh()) + ['rede' => $this->contadores($rede)]);
    }

    /** @return array{0: string, 1: string} */
    private function alvo(Request $request): array
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in([RedeMercadoVinculo::CLIENTE, RedeMercadoVinculo::LEAD])],
            'codigo' => ['required', 'string', 'max:20'],
        ]);

        $existe = $dados['tipo'] === RedeMercadoVinculo::CLIENTE
            ? Cliente::where('cod_cliente', $dados['codigo'])->exists()
            : Lead::whereKey($dados['codigo'])->where('status', '!=', 'excluido')->exists();

        abort_unless($existe, 422, 'Cliente ou lead não encontrado.');

        return [$dados['tipo'], $dados['codigo']];
    }

    /** Os números da linha da rede, para a tela atualizar sem recarregar a lista. */
    private function contadores(RedeMercado $rede): array
    {
        return [
            'id' => $rede->id,
            'clientes' => $rede->clientes,
            'filiais' => $rede->filiais,
            'clientesAtivos' => $rede->clientes_ativos,
            'leads' => $rede->leads,
            'vendedor' => $rede->vendedor,
            'ultimaCompra' => $rede->ultima_compra?->toDateString(),
            'status' => $rede->status,
        ];
    }
}
