<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem já faturou um produto, pelo nome que está na nota.
 *
 * A busca é em `faturamento_cliente_produto` (quem comprou qual código), escrita pelo
 * rollup. Varrer `faturamentos` por descrição não tem índice e não cabe no chat.
 *
 * ⚠️ O grão é o código do cliente, não a filial. Entra quem tem alguma filial na
 * carteira de quem perguntou. A lista não diz que outra carteira comprou: "não
 * encontrado" é a mesma frase para "ninguém comprou" e "comprou, mas não é seu".
 */
final class QuemComprou implements Ferramenta
{
    private const LIMITE = 15;

    private const PRODUTOS_POR_CLIENTE = 3;

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly NomeVendedorResolver $nomes,
    ) {}

    public function nome(): string
    {
        return 'quem_comprou';
    }

    public function rotuloProgresso(): string
    {
        return 'Procurando quem comprou esse produto…';
    }

    public function descricao(): string
    {
        return 'Quem já faturou um produto, pelo trecho do nome na nota (ex.: "rótulo", '
            .'"bobina 80x40", "friboi"). Use para "já vendemos X?", "quem compra rótulo", '
            .'"quem comprou esse produto". Devolve as empresas da carteira de quem perguntou, '
            .'o código do produto e a primeira e a última nota. Não devolve valor. Não abra '
            .'chamado nem mande procurar no TOTVS: esta ferramenta é o caminho.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => ['type' => 'string', 'description' => 'Trecho do nome do produto. Mínimo 3 caracteres.'],
            ],
            'required' => ['termo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $termo = trim((string) ($entrada['termo'] ?? ''));
        $termo = str_replace(['%', '_'], '', $termo);

        if (mb_strlen($termo) < 3) {
            return ['clientes' => [], 'mensagem' => 'Use pelo menos 3 caracteres do nome do produto.'];
        }

        $like = '%'.addcslashes($termo, '\\%_').'%';
        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $base = $this->notas($like, $codigos);

        $total = (int) (clone $base)->distinct()->count('f.cod_cliente');
        $linhas = (clone $base)
            ->groupBy('f.cod_cliente')
            ->orderByRaw('MAX(f.ultima_emissao) DESC')
            ->limit(self::LIMITE)
            ->get([
                'f.cod_cliente',
                DB::raw('MIN(f.primeira_emissao) as de'),
                DB::raw('MAX(f.ultima_emissao) as ate'),
            ]);

        $clientes = $this->montar($linhas, $like, $codigos);

        return [
            'termo' => $termo,
            'definicao' => 'Nota fiscal cujo nome do produto contém o termo. Não é pedido em aberto e não traz valor. Só entram empresas da carteira de quem perguntou.',
            'total_empresas' => $total,
            'clientes' => $clientes,
            'no_catalogo_sem_nota' => $this->semNota($like),
            'mensagem' => $this->mensagem($total, $clientes),
        ];
    }

    /**
     * @param  array<int, string>|null  $codigos
     */
    private function notas(string $like, ?array $codigos)
    {
        $q = DB::table('faturamento_cliente_produto as f')
            ->where('f.descricao', 'like', $like);

        if ($codigos !== null) {
            $q->whereExists(function ($sub) use ($codigos) {
                $sub->selectRaw('1')
                    ->from('clientes as c')
                    ->whereColumn('c.cod_cliente', 'f.cod_cliente')
                    ->whereIn('c.cod_vendedor', $codigos);
            });
        }

        return $q;
    }

    /**
     * @param  Collection<int, object>  $linhas
     * @param  array<int, string>|null  $codigos
     * @return list<array<string, mixed>>
     */
    private function montar(Collection $linhas, string $like, ?array $codigos): array
    {
        if ($linhas->isEmpty()) {
            return [];
        }

        $ids = $linhas->pluck('cod_cliente')->all();
        $filiais = Cliente::query()
            ->whereIn('cod_cliente', $ids)
            ->when($codigos !== null, fn ($q) => $q->whereIn('cod_vendedor', $codigos))
            ->get(['cod_cliente', 'razao_social', 'nome_fantasia', 'cod_vendedor']);
        $porCodigo = $filiais->groupBy('cod_cliente');
        $nomes = $this->nomes->porCodigo($filiais->pluck('cod_vendedor'));

        $produtos = DB::table('faturamento_cliente_produto')
            ->where('descricao', 'like', $like)
            ->whereIn('cod_cliente', $ids)
            ->orderByDesc('ultima_emissao')
            ->get(['cod_cliente', 'cod_produto', 'descricao', 'ultima_emissao'])
            ->groupBy('cod_cliente');

        return $linhas->map(function (object $linha) use ($porCodigo, $nomes, $produtos) {
            $doCodigo = $porCodigo->get($linha->cod_cliente, collect());
            $razao = $doCodigo->groupBy('razao_social')->sortByDesc(fn ($g) => $g->count())->keys()->first();
            $vendedores = $doCodigo->pluck('cod_vendedor')->unique()->filter()->values()
                ->map(fn ($codigo) => ['codigo' => $codigo, 'nome' => $nomes[$codigo] ?? $codigo])
                ->all();

            return [
                'razao_social' => $razao,
                'codigo_cliente' => $linha->cod_cliente,
                'vendedores' => $vendedores,
                'primeira_nota' => Formato::data(Carbon::parse($linha->de)),
                'ultima_nota' => Formato::data(Carbon::parse($linha->ate)),
                'produtos' => $produtos->get($linha->cod_cliente, collect())
                    ->unique('cod_produto')
                    ->take(self::PRODUTOS_POR_CLIENTE)
                    ->map(fn ($p) => ['codigo' => $p->cod_produto, 'descricao' => $p->descricao])
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    /** @return list<array{codigo: string, descricao: string}> */
    private function semNota(string $like): array
    {
        return DB::table('produtos as p')
            ->leftJoin('faturamento_cliente_produto as f', 'f.cod_produto', '=', 'p.cod_produto')
            ->where('p.descricao', 'like', $like)
            ->whereNull('f.cod_produto')
            ->limit(5)
            ->get(['p.cod_produto', 'p.descricao'])
            ->map(fn ($p) => ['codigo' => $p->cod_produto, 'descricao' => $p->descricao])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $clientes
     */
    private function mensagem(int $total, array $clientes): ?string
    {
        if ($total === 0) {
            return 'Nenhuma empresa da carteira de quem perguntou faturou produto com esse nome. Não diga que outra carteira comprou.';
        }

        if ($total > count($clientes)) {
            return 'A lista é das '.count($clientes).' empresas com a nota mais recente, de '.$total.' no total.';
        }

        return null;
    }
}
