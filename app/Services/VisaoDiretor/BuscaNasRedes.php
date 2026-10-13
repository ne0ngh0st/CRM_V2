<?php

namespace App\Services\VisaoDiretor;

use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A ÚNICA regra de "esta rede casa com a busca" da Visão Diretor (decisão do Tony,
 * 2026-10-13: digitar "OBA" tem que trazer a FARTURA, porque o Oba Hortifruti está nela).
 *
 * Uma rede casa se:
 * Casar = começo de palavra (`TermoDeBusca`): "OBA" acha "OBA HORTIFRUTI", não "COBASI".
 *
 * - o NOME ou um APELIDO dela ("Também conhecida como") casa; ou
 * - alguma FILIAL ligada a ela (pelos vínculos, a mesma definição de `ClientesDaConta`)
 *   tem nome fantasia, razão social ou CNPJ que contém o termo; ou
 * - algum LEAD ligado a ela com confirmação tem nome, razão social ou CNPJ que contém o termo.
 *
 * Quem casou por dentro volta com o motivo (`encontradoPor`), que a tela mostra embaixo do
 * nome da rede. Sem isso, um cliente que está dentro de uma rede SUMIA da busca: ele não
 * aparece solto (é da rede) e a rede não aparecia (o nome dela é outro).
 */
class BuscaNasRedes
{
    /** Quantos nomes de exemplo voltam por rede (o resto vira "+N"). */
    private const EXEMPLOS = 3;

    public function __construct(private readonly ClientesDaConta $clientesDaConta)
    {
    }

    /**
     * As redes que casam com a busca, cada uma com `encontradoPor` (null quando casou pelo
     * próprio nome). Sem busca, devolve as redes como vieram.
     *
     * @param  Collection<int, array>  $redes  linhas do `MaioresPorSegmentoResolver`
     * @return Collection<int, array>
     */
    public function filtrar(Collection $redes, string $busca): Collection
    {
        $busca = trim($busca);
        if ($busca === '' || $redes->isEmpty()) {
            return $redes;
        }

        $porDentro = $this->encontradosDentro($redes->pluck('id')->all(), $busca);

        return $redes
            ->map(function (array $r) use ($busca, $porDentro) {
                if (TermoDeBusca::casa($r['nome'], $busca)) {
                    return [...$r, 'encontradoPor' => null];
                }

                $apelido = collect(self::apelidos($r['apelidos'] ?? null))
                    ->first(fn (string $a) => TermoDeBusca::casa($a, $busca));
                if ($apelido !== null) {
                    return [...$r, 'encontradoPor' => ['nomes' => [$apelido], 'total' => 1, 'origem' => 'apelido']];
                }

                return isset($porDentro[$r['id']]) ? [...$r, 'encontradoPor' => $porDentro[$r['id']]] : null;
            })
            ->filter()
            ->values();
    }

    /** "OBA, Oba Hortifruti" → ["OBA", "Oba Hortifruti"]. */
    public static function apelidos(?string $texto): array
    {
        return collect(explode(',', (string) $texto))
            ->map(fn (string $a) => trim($a))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Filiais e leads das redes cujo nome/razão/CNPJ contém o termo. Duas consultas para
     * todas as redes, não uma por rede.
     *
     * @param  list<int>  $contas
     * @return array<int, array{nomes: list<string>, total: int, origem: string}>
     */
    private function encontradosDentro(array $contas, string $busca): array
    {
        /*
         * O Resumo filtra a mesma lista três vezes (KPIs, abas, tabela) com a mesma busca:
         * guarda o resultado para não repetir as duas consultas.
         */
        $chave = md5($busca.'|'.implode(',', $contas));

        return $this->memo[$chave] ??= $this->consultarDentro($contas, $busca);
    }

    /** @var array<string, array<int, array>> */
    private array $memo = [];

    /**
     * @param  list<int>  $contas
     * @return array<int, array{nomes: list<string>, total: int, origem: string}>
     */
    private function consultarDentro(array $contas, string $busca): array
    {
        [$casaFilial, $bFilial] = TermoDeBusca::sql(['c.nome_fantasia', 'c.razao_social'], $busca, 'c.cnpj_digitos');
        [$casaLead, $bLead] = TermoDeBusca::sql(['nome', 'razao_social'], $busca, "REGEXP_REPLACE(cnpj, '[^0-9]', '')");

        $filiais = DB::query()
            ->fromSub($this->clientesDaConta->paresDeTodasAsContas($contas), 'p')
            ->join('clientes as c', 'c.id', '=', 'p.cliente_id')
            ->whereRaw($casaFilial, $bFilial)
            ->select('p.conta_id', 'c.cod_cliente')
            ->selectRaw("MIN(COALESCE(NULLIF(c.nome_fantasia, ''), c.razao_social)) as nome")
            ->groupBy('p.conta_id', 'c.cod_cliente')
            ->get();

        $leads = Lead::query()
            ->ligadoAConta()
            ->whereIn('conta_estrategica_id', $contas ?: [0])
            ->whereRaw($casaLead, $bLead)
            ->get(['conta_estrategica_id as conta_id', 'nome']);

        return $filiais->map(fn ($f) => ['conta_id' => $f->conta_id, 'nome' => $f->nome])
            ->concat($leads->map(fn ($l) => ['conta_id' => $l->conta_id, 'nome' => $l->nome]))
            ->groupBy('conta_id')
            ->map(fn (Collection $achados) => [
                'nomes' => $achados->pluck('nome')->unique()->take(self::EXEMPLOS)->values()->all(),
                // Clientes (por código) + leads achados; nomes repetidos contam pelo que são.
                'total' => $achados->count(),
                'origem' => 'dentro',
            ])
            ->all();
    }
}
