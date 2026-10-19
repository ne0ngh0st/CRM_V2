<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategicaVinculo;
use Illuminate\Support\Facades\DB;

/**
 * Último faturamento anual de uma empresa, para a planilha de pesquisa do supermercadista.
 *
 * A chave é o CNPJ de 14 dígitos da âncora, ou `c` + id da conta quando a rede da ABRAS
 * não tem CNPJ. A ABRAS prevalece: gravar o ranking substitui um valor que a web tinha
 * achado para a mesma empresa.
 */
class FaturamentoDeMercado
{
    public const FONTE_ABRAS = 'abras';

    public const FONTE_WEB = 'web';

    /**
     * @param  array<int, array<string, mixed>>  $doRanking  conta_id → linha do JSON
     * @param  array<int, list<string>>  $codigosPorConta  conta_id → cod_cliente ligados nesta rodada
     */
    public function propagar(array $doRanking, array $codigosPorConta, int $ano): void
    {
        $semLista = array_keys(array_diff_key($doRanking, $codigosPorConta));
        $pelosVinculos = $semLista === [] ? [] : ContaEstrategicaVinculo::query()
            ->whereIn('conta_id', $semLista)
            ->where('tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)
            ->get(['conta_id', 'codigo'])
            ->groupBy('conta_id')
            ->map(fn ($g) => $g->pluck('codigo')->all())
            ->all();

        foreach ($doRanking as $id => $r) {
            if (! isset($r['faturamento']) || ! is_numeric($r['faturamento'])) {
                continue;
            }

            $codigos = $codigosPorConta[$id] ?? ($pelosVinculos[$id] ?? []);
            $this->gravarAbras((int) $id, (int) $r['faturamento'], $ano, $this->ancoras($codigos));
        }
    }

    /**
     * Grava o que a web declarou. Devolve false quando a chave já é da ABRAS — essa fonte
     * prevalece, e uma busca não a substitui.
     */
    public function gravarWeb(string $chave, ?int $valor, ?int $ano, ?string $url, ?int $contaId): bool
    {
        $atual = DB::table('faturamento_mercado')->where('chave', $chave)->first();
        if ($atual && $atual->fonte === self::FONTE_ABRAS) {
            return false;
        }

        $dados = [
            'conta_id' => $contaId ?? ($atual->conta_id ?? null),
            'valor' => $valor,
            'ano' => $ano,
            'fonte' => self::FONTE_WEB,
            'url' => $url !== '' ? $url : null,
            'consultado_em' => now(),
        ];

        if ($atual) {
            DB::table('faturamento_mercado')->where('chave', $chave)->where('fonte', '<>', self::FONTE_ABRAS)->update($dados);
        } else {
            DB::table('faturamento_mercado')->insert(['chave' => $chave, ...$dados]);
        }

        return true;
    }

    /** Apaga o faturamento da ABRAS desta conta. Um valor achado na web, de outra chave, fica. */
    public function limparAbras(int $contaId): void
    {
        DB::table('faturamento_mercado')
            ->where('conta_id', $contaId)
            ->where('fonte', self::FONTE_ABRAS)
            ->delete();
    }

    /**
     * Uma chave por empresa (âncora). Sem CNPJ, a chave é a da conta — a linha da rede
     * sem cliente lê por ali.
     *
     * @param  list<string>  $cnpjs  âncoras de 14 dígitos
     */
    public function gravarAbras(int $contaId, int $valor, int $ano, array $cnpjs): void
    {
        $chaves = $cnpjs !== [] ? array_values(array_unique($cnpjs)) : ['c'.$contaId];

        DB::table('faturamento_mercado')
            ->where('conta_id', $contaId)
            ->where('fonte', self::FONTE_ABRAS)
            ->whereNotIn('chave', $chaves)
            ->delete();

        foreach ($chaves as $chave) {
            DB::table('faturamento_mercado')->updateOrInsert(
                ['chave' => $chave],
                [
                    'conta_id' => $contaId,
                    'valor' => $valor,
                    'ano' => $ano,
                    'fonte' => self::FONTE_ABRAS,
                    'url' => null,
                    'consultado_em' => now(),
                ],
            );
        }
    }

    /**
     * CNPJ da âncora de cada empresa ligada: a matriz (/0001) quando existe.
     *
     * @param  list<string>  $codigos
     * @return list<string>
     */
    public function ancoras(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        $porRaiz = [];
        foreach (DB::table('clientes')->whereIn('cod_cliente', $codigos)->whereRaw('CHAR_LENGTH(cnpj_digitos) = 14')->get(['id', 'cnpj_digitos']) as $f) {
            $porRaiz[substr($f->cnpj_digitos, 0, 8)][] = $f;
        }

        $cnpjs = [];
        foreach ($porRaiz as $grupo) {
            usort($grupo, function ($a, $b) {
                $ma = substr($a->cnpj_digitos, 8, 4) === '0001' ? 0 : 1;
                $mb = substr($b->cnpj_digitos, 8, 4) === '0001' ? 0 : 1;

                return $ma <=> $mb ?: $a->id <=> $b->id;
            });
            $cnpjs[] = $grupo[0]->cnpj_digitos;
        }

        return $cnpjs;
    }
}
