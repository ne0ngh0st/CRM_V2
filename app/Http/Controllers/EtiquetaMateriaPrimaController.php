<?php

namespace App\Http\Controllers;

use App\Models\EtiquetaMateriaPrima;
use App\Services\Etiquetas\EtiquetaPrecificadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de matéria-prima de etiqueta (custo R$/m² por material) e o endpoint de
 * cálculo da calculadora de precificação. Estritamente admin-only — cost/margem
 * interno não deve ser exposto a nenhum outro perfil (nem tela, nem endpoint).
 */
class EtiquetaMateriaPrimaController extends Controller
{
    public function __construct(
        private readonly EtiquetaPrecificadorService $precificador,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->autorizarAdmin($request);

        $busca = trim((string) $request->string('busca'));
        $status = (string) $request->string('status') ?: 'todos';

        $query = EtiquetaMateriaPrima::query();

        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('desc_mp', 'like', "%{$busca}%")
                    ->orWhere('cod_mp', 'like', "%{$busca}%")
                    ->orWhere('cod_comercial', 'like', "%{$busca}%")
                    ->orWhere('fabricante', 'like', "%{$busca}%");
            });
        }

        match ($status) {
            'ativa' => $query->where('ativo', true),
            'inativa' => $query->where('ativo', false),
            default => null,
        };

        $materiasPrimas = $query
            ->orderBy('desc_mp')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (EtiquetaMateriaPrima $mp) => [
                'id' => $mp->id,
                'categoria' => $mp->categoria,
                'fabricante' => $mp->fabricante,
                'codMp' => $mp->cod_mp,
                'codComercial' => $mp->cod_comercial,
                'descMp' => $mp->desc_mp,
                'largMp' => $mp->larg_mp !== null ? (float) $mp->larg_mp : null,
                'precoM2' => (float) $mp->preco_m2,
                'ativo' => $mp->ativo,
            ]);

        return Inertia::render('Etiquetas/MateriaPrima/Index', [
            'materiasPrimas' => $materiasPrimas,
            'filtros' => [
                'busca' => $busca,
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $data = $this->validarMateriaPrima($request);
        EtiquetaMateriaPrima::create($data);

        return back();
    }

    public function update(Request $request, EtiquetaMateriaPrima $materiaPrima): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $data = $this->validarMateriaPrima($request);
        $materiaPrima->update($data);

        return back();
    }

    public function destroy(Request $request, EtiquetaMateriaPrima $materiaPrima): RedirectResponse
    {
        $this->autorizarAdmin($request);

        abort_if($materiaPrima->itens()->exists(), 409, 'Matéria-prima usada em orçamentos — desative em vez de excluir.');

        $materiaPrima->delete();

        return back();
    }

    public function calcular(Request $request): JsonResponse
    {
        $this->autorizarAdmin($request);

        $data = $request->validate([
            'largura_util' => ['required', 'numeric', 'min:0'],
            'gap_lateral' => ['required', 'numeric', 'min:0'],
            'altura_util' => ['required', 'numeric', 'min:0'],
            'gap_supinf' => ['required', 'numeric', 'min:0'],
            'metros_rolo' => ['required', 'numeric', 'min:0.01'],
            'qtd_etiquetas' => ['required', 'numeric', 'min:1'],
            'materia_prima_id' => ['required', 'integer', 'exists:etiquetas_materia_prima,id'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
        ]);

        // O custo/m² nunca vem do cliente — sempre resolvido no servidor a partir do
        // material selecionado, pra não permitir manipular o cálculo de custo/margem.
        $materiaPrima = EtiquetaMateriaPrima::query()->findOrFail($data['materia_prima_id']);

        $resultado = $this->precificador->calcular([
            'largura_util' => (float) $data['largura_util'],
            'gap_lateral' => (float) $data['gap_lateral'],
            'altura_util' => (float) $data['altura_util'],
            'gap_supinf' => (float) $data['gap_supinf'],
            'metros_rolo' => (float) $data['metros_rolo'],
            'qtd_etiquetas' => (float) $data['qtd_etiquetas'],
            'preco_m2_materia_prima' => (float) $materiaPrima->preco_m2,
            'preco_venda' => isset($data['preco_venda']) ? (float) $data['preco_venda'] : null,
        ]);

        return response()->json($resultado);
    }

    private function validarMateriaPrima(Request $request): array
    {
        return $request->validate([
            'categoria' => ['nullable', 'string', 'max:120'],
            'fabricante' => ['nullable', 'string', 'max:150'],
            'cod_mp' => ['nullable', 'string', 'max:80'],
            'cod_comercial' => ['nullable', 'string', 'max:80'],
            'desc_mp' => ['required', 'string', 'max:255'],
            'larg_mp' => ['nullable', 'numeric', 'min:0'],
            'preco_m2' => ['required', 'numeric', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);
    }

    private function autorizarAdmin(Request $request): void
    {
        abort_unless($request->user()->getRoleNames()->first() === 'admin', 403);
    }
}
