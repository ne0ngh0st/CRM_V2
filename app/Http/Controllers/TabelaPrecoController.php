<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TabelaPrecoController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $busca = trim((string) $request->string('busca'));
        $categoria = trim((string) $request->string('categoria'));
        $preco = (string) $request->string('preco') ?: 'todos';
        $ordenar = (string) $request->string('ordenar') ?: 'codigo_asc';

        $baseQuery = fn () => $this->baseQuery($request);

        $kpis = [
            'total' => (clone $baseQuery())->count(),
            'categorias' => (int) (clone $baseQuery())->whereNotNull('categoria')->where('categoria', '!=', '')->count(DB::raw('DISTINCT categoria')),
            'comPreco' => (clone $baseQuery())->whereNotNull('preco_tabela')->where('preco_tabela', '>', 0)->count(),
            'semPreco' => (clone $baseQuery())->where(fn ($q) => $q->whereNull('preco_tabela')->orWhere('preco_tabela', '<=', 0))->count(),
        ];

        $produtos = $this->listaQuery($request)
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Produto $p) => [
                'id' => $p->id,
                'codProduto' => $p->cod_produto,
                'descricao' => $p->descricao,
                'categoria' => $p->categoria,
                'unidade' => $p->unidade,
                'precoTabela' => $p->preco_tabela !== null ? (float) $p->preco_tabela : null,
            ]);

        $categorias = Produto::query()
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->select('categoria', DB::raw('COUNT(*) as total'))
            ->groupBy('categoria')
            ->orderBy('categoria')
            ->get()
            ->map(fn ($row) => [
                'nome' => $row->categoria,
                'total' => (int) $row->total,
            ]);

        return Inertia::render('TabelaPrecos/Index', [
            'role' => $role,
            'produtos' => $produtos,
            'kpis' => $kpis,
            'filtros' => [
                'busca' => $busca,
                'categoria' => $categoria,
                'preco' => $preco,
                'ordenar' => $ordenar,
            ],
            'opcoes' => [
                'categorias' => $categorias,
            ],
        ]);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        // Catálogo tem ~27k produtos — margem de segurança, ver CarteiraController::exportar().
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return Excel::download(
            new \App\Exports\TabelaPrecoExport($this->listaQuery($request)),
            'tabela-precos-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Busca/categoria/preço, sem escopo por perfil (catálogo é igual pra todo mundo). Usado por index() (KPIs e lista) e exportar(). */
    protected function baseQuery(Request $request): Builder
    {
        $busca = trim((string) $request->string('busca'));
        $categoria = trim((string) $request->string('categoria'));
        $preco = (string) $request->string('preco') ?: 'todos';

        $query = Produto::query();

        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('cod_produto', 'like', "%{$busca}%")
                    ->orWhere('descricao', 'like', "%{$busca}%");
            });
        }

        if ($categoria !== '') {
            $query->where('categoria', $categoria);
        }

        match ($preco) {
            'com_preco' => $query->whereNotNull('preco_tabela')->where('preco_tabela', '>', 0),
            'sem_preco' => $query->where(fn ($q) => $q->whereNull('preco_tabela')->orWhere('preco_tabela', '<=', 0)),
            default => null,
        };

        return $query;
    }

    /** baseQuery() + ordenação. Usado por index() (lista) e exportar(). */
    protected function listaQuery(Request $request): Builder
    {
        $query = $this->baseQuery($request);
        $ordenar = (string) $request->string('ordenar') ?: 'codigo_asc';

        match ($ordenar) {
            'codigo_desc' => $query->orderByDesc('cod_produto'),
            'descricao_asc' => $query->orderBy('descricao'),
            'descricao_desc' => $query->orderByDesc('descricao'),
            'preco_asc' => $query->orderByRaw('preco_tabela IS NULL, preco_tabela ASC'),
            'preco_desc' => $query->orderByRaw('preco_tabela IS NULL, preco_tabela DESC'),
            'categoria_asc' => $query->orderByRaw('categoria IS NULL, categoria ASC')->orderBy('cod_produto'),
            default => $query->orderBy('cod_produto'),
        };

        return $query;
    }
}
