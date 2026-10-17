<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Receita\SituacaoCadastral;
use App\Services\Totvs\Normalizador;
use App\Services\VisaoDiretor\TermoDeBusca;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LEITURA da página Maiores redes. Lê só `redes_mercado` (~900 linhas, contadores já
 * calculados por `MaioresRedes`) — nada é agregado ao vivo.
 *
 * Status de rede: só **Ativo** e **A trabalhar** (Tony, 2026-10-16). Os tiles contam a
 * categoria inteira com a busca/UF aplicadas e SEM o filtro de status (um filtro não se
 * aplica à faceta que ele controla — mesma regra do card da Carteira). O tile "CNPJ
 * irregular" idem, com o filtro da Receita.
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
     * Os filtros da URL, validados. Uma definição só para a tela e para o Excel: o que a
     * tela aceita como filtro é exatamente o que a planilha leva.
     *
     * @param  array<string, mixed>  $q
     * @return array{categoria: string, status: string, uf: string, busca: string, receita: string}
     */
    public static function filtros(array $q): array
    {
        return [
            // 'irregular' = a rede ou um cliente nosso dela com CNPJ não ativo na Receita.
            'receita' => ($q['receita'] ?? null) === 'irregular' ? 'irregular' : '',
            'categoria' => array_key_exists((string) ($q['categoria'] ?? ''), CategoriasDeRede::CATEGORIAS) ? (string) $q['categoria'] : '',
            // Whitelist: o status vem da URL e vira WHERE.
            'status' => in_array($q['status'] ?? null, [RedeMercado::STATUS_ATIVO, RedeMercado::STATUS_A_TRABALHAR], true) ? (string) $q['status'] : '',
            'uf' => preg_match('/^[A-Z]{2}$/', (string) ($q['uf'] ?? '')) ? (string) $q['uf'] : '',
            'busca' => mb_substr(trim((string) ($q['busca'] ?? '')), 0, 80),
        ];
    }

    /**
     * As redes do recorte (categoria vazia = todas), SEM o filtro de status: os tiles
     * contam a faceta inteira. `ordenada()` aplica status e ordem.
     *
     * @param  array{categoria: string, status: string, busca: string, uf: string}  $filtros
     */
    private function base(array $filtros): Builder
    {
        return RedeMercado::where('ativa', true)
            ->when($filtros['categoria'] !== '', fn ($q) => $q->where('categoria', $filtros['categoria']))
            ->when($filtros['uf'] !== '', fn ($q) => $q->where('uf', $filtros['uf']))
            ->when(($filtros['receita'] ?? '') === 'irregular', fn ($q) => $this->comCnpjIrregular($q))
            ->when($filtros['busca'] !== '', function ($q) use ($filtros) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtoupper(trim($filtros['busca']))).'%';
                $q->where(fn ($w) => $w->where('nome', 'like', $termo)->orWhere('linha_original', 'like', $termo)->orWhere('vendedor', 'like', $termo));
            });
    }

    /**
     * Rede com o CNPJ dela não ativo na Receita, OU com filial de cliente nosso nessa
     * situação. "Não ativo" é `SituacaoCadastral::irregular()` — a mesma régua da Carteira.
     * Só há CNPJ gravado quando a busca confia nele (ou foi informado à mão).
     */
    private function comCnpjIrregular(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('filiais_cnpj_irregular', '>', 0)
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('cnpj_situacoes')
                ->whereColumn('cnpj_situacoes.cnpj', 'redes_mercado.cnpj')
                ->whereRaw(SituacaoCadastral::sqlIrregular('cnpj_situacoes.situacao'))));
    }

    /**
     * O CNPJ da rede como a tela e o Excel o mostram.
     *
     * @param  array{situacao: string, data: ?string}|null  $situacao  de `SituacaoCadastral::detalhes()`
     */
    public static function receita(RedeMercado $r, ?array $situacao): array
    {
        return [
            'cnpj' => Normalizador::documento($r->cnpj),
            'cnpjRazaoSocial' => $r->cnpj_razao_social,
            'cnpjConfianca' => $r->cnpj_confianca,
            'cnpjConfiancaRotulo' => CnpjDasRedes::CONFIANCAS[$r->cnpj_confianca] ?? null,
            'cnpjSituacao' => $r->cnpj ? ($situacao['situacao'] ?? null) : null,
            'cnpjSituacaoData' => $r->cnpj ? ($situacao['data'] ?? null) : null,
            'cnpjIrregular' => $r->cnpj !== null && SituacaoCadastral::irregular($situacao['situacao'] ?? null),
            'cnpjLojasAtivas' => $r->cnpj_lojas_ativas,
            'filiaisCnpjIrregular' => $r->filiais_cnpj_irregular,
        ];
    }

    /** @return array<string, array{situacao: string, data: ?string}> */
    public function situacoesDas(iterable $redes): array
    {
        return app(SituacaoCadastral::class)->detalhes(collect($redes)->pluck('cnpj')->filter()->all());
    }

    /** O recorte com o status e na ordem da tela — a lista e o Excel saem daqui. */
    public function consulta(array $filtros): Builder
    {
        return $this->base($filtros)
            ->when($filtros['status'] !== '', fn ($q) => $q->where('status', $filtros['status']))
            // Maiores primeiro; bandeira sem número de lojas no fim, na ordem da planilha.
            // Com todas as categorias (Excel do Resumo), agrupadas na ordem das abas.
            ->when($filtros['categoria'] === '', fn ($q) => $q->orderByRaw('FIELD(categoria, '.implode(',', array_fill(0, count(CategoriasDeRede::CATEGORIAS), '?')).')', array_keys(CategoriasDeRede::CATEGORIAS)))
            ->orderByRaw('lojas IS NULL')->orderByDesc('lojas')->orderBy('posicao')->orderBy('id');
    }

    /**
     * @param  array{status: string, busca: string, uf: string, receita: string}  $filtros
     * @return array{categoria: string, contagens: array{total: int, ativo: int, a_trabalhar: int, irregular: int}, linhas: list<array>}
     */
    public function lista(string $categoria, array $filtros): array
    {
        $filtros = ['categoria' => $categoria] + $filtros;

        $c = $this->base($filtros)->selectRaw("COUNT(*) as total, SUM(status = 'ativo') as ativo")->first();
        $total = (int) $c->total;
        $ativo = (int) $c->ativo;

        // O tile da Receita conta o recorte SEM o próprio filtro (mesma regra do status).
        $irregular = $this->base(['receita' => 'irregular'] + $filtros)->count();

        $redes = $this->consulta($filtros)->get();
        $situacoes = $this->situacoesDas($redes);

        return [
            'categoria' => $categoria,
            'contagens' => ['total' => $total, 'ativo' => $ativo, 'a_trabalhar' => $total - $ativo, 'irregular' => $irregular],
            'linhas' => $redes->map(fn (RedeMercado $r) => self::receita($r, $situacoes[$r->cnpj] ?? null) + [
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
