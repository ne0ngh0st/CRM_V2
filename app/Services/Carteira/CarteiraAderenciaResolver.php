<?php

namespace App\Services\Carteira;

use Illuminate\Database\Eloquent\Builder;

/**
 * Aderência de carteira: cada vendedor atende 0+ segmentos (setor do cliente —
 * supermercadista, órgão público etc., ver SegmentoVendedor); esse relatório
 * cruza status (ativo/inativando/inativo) com "dentro" ou "fora" do(s)
 * segmento(s) do vendedor responsável por cada cliente. Equivalente ao
 * `carteira_clientes_stats_aderencia_completa` do legado, mas sem a camada de
 * de-para de segmento/tipos especiais (INATIVOS GERAL, PRIMEIRO CONTATO) —
 * cortada de propósito, é complexidade de página própria, não de widget.
 *
 * Vendedor sem NENHUM segmento definido (`segmentos_vendedor` vazio pra esse
 * cod_vendedor) não é "dentro" nem "fora" — não há segmento nenhum pra medir
 * aderência contra. Esses clientes ficam num terceiro grupo (`semSegmentoDefinido`)
 * e são excluídos do denominador de `pctDentro`/`pctFora`, senão a métrica mentiria
 * pra pior (0%, como se fosse indisciplina) ou pra melhor (100%, como se fosse
 * aderência perfeita) num caso que é só falta de cadastro. Confirmado com Tony
 * em 2026-07-29.
 *
 * Recebe um Builder (não uma Collection já carregada) e faz a contagem inteira
 * em SQL — dois LEFT JOINs pequenos (`segmentos` tem 23 linhas, `segmentos_vendedor`
 * ~200) + uma subquery EXISTS correlacionada (mesma tabela pequena) + um CASE pelos
 * limiares de data já indexados. Isso é o que permite essa mesma classe atender
 * tanto o widget da Home quanto a página cheia de Carteira (até ~90k clientes) sem
 * carregar nada em memória.
 */
class CarteiraAderenciaResolver
{
    public function __construct(private readonly ClienteStatusResolver $statusResolver)
    {
    }

    public function resolver(Builder $query): array
    {
        $limiteAtivo = $this->statusResolver->limiteAtivo()->toDateString();
        $limiteInativando = $this->statusResolver->limiteInativando()->toDateString();

        $linhas = (clone $query)
            ->leftJoin('segmentos', 'segmentos.codigo', '=', 'clientes.cod_segmento')
            ->leftJoin('segmentos_vendedor', function ($join) {
                $join->on('segmentos_vendedor.cod_vendedor', '=', 'clientes.cod_vendedor')
                    ->on('segmentos_vendedor.segmento_id', '=', 'segmentos.id');
            })
            // select([]) limpa qualquer select que a query recebida já tivesse (ex.: a
            // página de Carteira usa `select('clientes.*')` pra montar a listagem) — sem
            // isso, colunas não agregadas fora do GROUP BY quebram em sql_mode=only_full_group_by.
            ->select([])
            ->selectRaw('
                CASE
                    WHEN clientes.data_ultima_compra >= ? THEN \'ativo\'
                    WHEN clientes.data_ultima_compra >= ? THEN \'inativando\'
                    ELSE \'inativo\'
                END as status_carteira,
                CASE
                    WHEN NOT EXISTS (SELECT 1 FROM segmentos_vendedor sv2 WHERE sv2.cod_vendedor = clientes.cod_vendedor) THEN \'sem_segmento\'
                    WHEN segmentos_vendedor.id IS NOT NULL THEN \'dentro\'
                    ELSE \'fora\'
                END as aderencia,
                COUNT(*) as total
            ', [$limiteAtivo, $limiteInativando])
            ->groupBy('status_carteira', 'aderencia')
            ->get();

        $dentro = ['ativo' => 0, 'inativando' => 0, 'inativo' => 0];
        $fora = ['ativo' => 0, 'inativando' => 0, 'inativo' => 0];
        $semSegmento = ['ativo' => 0, 'inativando' => 0, 'inativo' => 0];

        foreach ($linhas as $linha) {
            match ($linha->aderencia) {
                'dentro' => $dentro[$linha->status_carteira] += (int) $linha->total,
                'fora' => $fora[$linha->status_carteira] += (int) $linha->total,
                default => $semSegmento[$linha->status_carteira] += (int) $linha->total,
            };
        }

        $totalDentro = array_sum($dentro);
        $totalFora = array_sum($fora);
        $totalSemSegmento = array_sum($semSegmento);
        $totalMensuravel = $totalDentro + $totalFora;

        return [
            'total' => $totalMensuravel + $totalSemSegmento,
            'dentroSegmento' => $this->comPercentuais($dentro),
            'foraSegmento' => $this->comPercentuais($fora),
            'semSegmentoDefinido' => $this->comPercentuais($semSegmento),
            'pctDentro' => $totalMensuravel > 0 ? round($totalDentro / $totalMensuravel * 100, 1) : 0.0,
            'pctFora' => $totalMensuravel > 0 ? round($totalFora / $totalMensuravel * 100, 1) : 0.0,
        ];
    }

    /** @param array{ativo: int, inativando: int, inativo: int} $contagens */
    private function comPercentuais(array $contagens): array
    {
        $total = array_sum($contagens);
        $pct = fn (int $n) => $total > 0 ? round($n / $total * 100, 1) : 0.0;

        return [
            'total' => $total,
            'ativos' => $contagens['ativo'],
            'inativando' => $contagens['inativando'],
            'inativos' => $contagens['inativo'],
            'pctAtivos' => $pct($contagens['ativo']),
            'pctInativando' => $pct($contagens['inativando']),
            'pctInativos' => $pct($contagens['inativo']),
        ];
    }
}
