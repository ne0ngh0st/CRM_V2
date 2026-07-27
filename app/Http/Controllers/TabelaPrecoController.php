<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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

        $baseQuery = function () use ($busca, $categoria, $preco) {
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
        };

        $kpis = [
            'total' => (clone $baseQuery())->count(),
            'categorias' => (int) (clone $baseQuery())->whereNotNull('categoria')->where('categoria', '!=', '')->count(DB::raw('DISTINCT categoria')),
            'comPreco' => (clone $baseQuery())->whereNotNull('preco_tabela')->where('preco_tabela', '>', 0)->count(),
            'semPreco' => (clone $baseQuery())->where(fn ($q) => $q->whereNull('preco_tabela')->orWhere('preco_tabela', '<=', 0))->count(),
        ];

        $listaQuery = $baseQuery();

        match ($ordenar) {
            'codigo_desc' => $listaQuery->orderByDesc('cod_produto'),
            'descricao_asc' => $listaQuery->orderBy('descricao'),
            'descricao_desc' => $listaQuery->orderByDesc('descricao'),
            'preco_asc' => $listaQuery->orderByRaw('preco_tabela IS NULL, preco_tabela ASC'),
            'preco_desc' => $listaQuery->orderByRaw('preco_tabela IS NULL, preco_tabela DESC'),
            'categoria_asc' => $listaQuery->orderByRaw('categoria IS NULL, categoria ASC')->orderBy('cod_produto'),
            default => $listaQuery->orderBy('cod_produto'),
        };

        $produtos = $listaQuery
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
}
