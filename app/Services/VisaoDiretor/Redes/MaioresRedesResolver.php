<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\VisaoDiretor\TermoDeBusca;
use Illuminate\Support\Facades\DB;

/**
 * LEITURA da página Maiores redes. Lê só `redes_mercado` (~900 linhas, contadores já
 * calculados por `MaioresRedes`) — nada é agregado ao vivo.
 *
 * Status de rede: só **Ativo** e **A trabalhar** (Tony, 2026-10-16). Os tiles contam a
 * categoria inteira com a busca/UF aplicadas e SEM o filtro de status (um filtro não se
 * aplica à faceta que ele controla — mesma regra do card da Carteira).
 */
class MaioresRedesResolver
{
    public function __construct(private readonly BuscaTitularidade $titularidade)
    {
    }

    /** @return list<array{categoria: string, nome: string, total: int, ativo: int, a_trabalhar: int}> */
    public function resumo(): array
    {
        $contagem = RedeMercado::where('ativa', true)
            ->groupBy('categoria')
            ->selectRaw("categoria, COUNT(*) as total, SUM(status = 'ativo') as ativo")
            ->get()
            ->keyBy('categoria');

        $saida = [];
        foreach (CategoriasDeRede::CATEGORIAS as $chave => $c) {
            $linha = $contagem[$chave] ?? null;
            $total = (int) ($linha->total ?? 0);
            $ativo = (int) ($linha->ativo ?? 0);
            $saida[] = ['categoria' => $chave, 'nome' => $c['rotulo'], 'total' => $total, 'ativo' => $ativo, 'a_trabalhar' => $total - $ativo];
        }

        return $saida;
    }

    /**
     * @param  array{status: string, busca: string, uf: string}  $filtros
     * @return array{categoria: string, contagens: array{total: int, ativo: int, a_trabalhar: int}, linhas: list<array>}
     */
    public function lista(string $categoria, array $filtros): array
    {
        $base = RedeMercado::where('ativa', true)->where('categoria', $categoria)
            ->when($filtros['uf'] !== '', fn ($q) => $q->where('uf', $filtros['uf']))
            ->when($filtros['busca'] !== '', function ($q) use ($filtros) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtoupper(trim($filtros['busca']))).'%';
                $q->where(fn ($w) => $w->where('nome', 'like', $termo)->orWhere('linha_original', 'like', $termo)->orWhere('vendedor', 'like', $termo));
            });

        $c = (clone $base)->selectRaw("COUNT(*) as total, SUM(status = 'ativo') as ativo")->first();
        $total = (int) $c->total;
        $ativo = (int) $c->ativo;

        $redes = $base
            ->when($filtros['status'] !== '', fn ($q) => $q->where('status', $filtros['status']))
            // Maiores primeiro; bandeira sem número de lojas no fim, na ordem da planilha.
            ->orderByRaw('lojas IS NULL')->orderByDesc('lojas')->orderBy('posicao')->orderBy('id')
            ->get();

        return [
            'categoria' => $categoria,
            'contagens' => ['total' => $total, 'ativo' => $ativo, 'a_trabalhar' => $total - $ativo],
            'linhas' => $redes->map(fn (RedeMercado $r) => [
                'id' => $r->id,
                'posicao' => $r->posicao,
                'nome' => $r->nome,
                // A linha da planilha só aparece quando a rede veio de uma linha somada.
                'linhaOriginal' => mb_strtoupper($r->linha_original) !== $r->nome ? $r->linha_original : null,
                'uf' => $r->uf,
                'lojas' => $r->lojas,
                'qualidade' => $r->qualidade,
                'fonte' => $r->fonte,
                'dataBase' => $r->data_base,
                'clientes' => $r->clientes,
                'filiais' => $r->filiais,
                'clientesAtivos' => $r->clientes_ativos,
                'leads' => $r->leads,
                'vendedor' => $r->vendedor,
                'ultimaCompra' => $r->ultima_compra?->toDateString(),
                'status' => $r->status,
            ])->all(),
        ];
    }

    /**
     * O que a tela oferece para vincular: clientes (a busca de titularidade de Cadastros,
     * que já resolve nome/CNPJ/código pelo caminho rápido) e leads. Cada resultado diz em
     * que rede já está, para quem vincula saber que vai mudá-lo de lugar.
     *
     * @return list<array{tipo: string, codigo: string, nome: string, detalhe: string, documento: ?string, redeAtual: ?string}>
     */
    public function buscarParaVincular(string $termo): array
    {
        $termo = trim($termo);
        if (mb_strlen($termo) < BuscaTitularidade::MINIMO_CARACTERES) {
            return [];
        }

        $clientes = collect($this->titularidade->buscar($termo))
            ->where('tipo', 'cliente')
            ->unique('codCliente')
            ->take(20)
            ->map(fn (array $c) => [
                'tipo' => RedeMercadoVinculo::CLIENTE,
                'codigo' => (string) $c['codCliente'],
                'nome' => $c['razaoSocial'],
                'detalhe' => trim(($c['nomeFantasia'] ?? '').' · '.implode(', ', $c['responsaveis'] ?? []), ' ·'),
                'documento' => $c['cnpj'],
            ]);

        $digitos = preg_replace('/\D/', '', $termo);
        $leads = DB::table('leads')
            ->where('status', '!=', 'excluido')
            ->where(function ($q) use ($termo, $digitos) {
                [$sql, $bindings] = TermoDeBusca::sql(['leads.razao_social', 'leads.nome_fantasia', 'leads.nome'], $termo);
                $q->whereRaw($sql, $bindings);
                // 17 mil leads: o REPLACE na coluna varre a tabela, e cabe no orçamento.
                if (strlen($digitos) >= 4) {
                    $q->orWhereRaw("REPLACE(REPLACE(REPLACE(leads.cnpj, '.', ''), '/', ''), '-', '') LIKE ?", [$digitos.'%']);
                }
            })
            ->orderBy('razao_social')
            ->limit(10)
            ->get(['id', 'nome', 'razao_social', 'nome_fantasia', 'cnpj'])
            ->map(fn ($l) => [
                'tipo' => RedeMercadoVinculo::LEAD,
                'codigo' => (string) $l->id,
                'nome' => $l->razao_social ?: $l->nome,
                'detalhe' => 'Lead'.($l->nome_fantasia ? ' · '.$l->nome_fantasia : ''),
                'documento' => $l->cnpj,
            ]);

        $resultado = $clientes->concat($leads)->values();

        $redeAtual = RedeMercadoVinculo::query()
            ->join('redes_mercado', 'redes_mercado.id', '=', 'redes_mercado_vinculos.rede_id')
            ->where('recusado', false)
            ->whereIn(DB::raw("CONCAT(tipo, ':', codigo)"), $resultado->map(fn ($r) => $r['tipo'].':'.$r['codigo'])->all() ?: ['-'])
            ->get(['tipo', 'codigo', 'redes_mercado.nome'])
            ->mapWithKeys(fn ($v) => [$v->tipo.':'.$v->codigo => $v->nome]);

        return $resultado->map(fn (array $r) => $r + ['redeAtual' => $redeAtual[$r['tipo'].':'.$r['codigo']] ?? null])->all();
    }
}
