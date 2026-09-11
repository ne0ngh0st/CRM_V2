<?php

namespace App\Services\Carteira;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 *
 * ⚠️ CONTA CLIENTES (`cod_cliente`), NÃO FILIAIS — desde 2026-09-11, quando a Carteira
 * passou a listar uma linha por cliente. Os dois têm que contar a mesma coisa: o KPI
 * fica cinco centímetros acima da tabela, e "92.209 clientes" sobre uma lista de 39.692
 * é o caso que o Tony recusou em 08/09 no Segmentos Atendidos. Número que precisa de
 * legenda para não parecer errado já perdeu a confiança.
 *
 * ⚠️ O PREÇO DISSO É REAL E FOI MEDIDO — agrupar por cliente obriga a materializar os
 * grupos, e com os dois LEFT JOINs o MySQL desiste do índice (`type: ALL`):
 *
 *   escopo de um vendedor .....   2 ms ->    18 ms   (o caso dominante)
 *   escopo de supervisor ......   5 ms ->   197 ms
 *   escopo empresa ............   5 ms -> 1.385 ms
 *
 * Aceito, e o motivo é a distribuição: quase todo acesso é de vendedor. O escopo
 * empresa é UM só, cacheado e aquecido por `AquecerCacheDashboardJob` — quem paga os
 * 1,4 s é o job, não a pessoa. Está abaixo dos 2 s que a Regra de ouro nº 9 manda
 * tornar assíncrono, e já roda em fila de qualquer forma.
 *
 * ⚠️ Tentado e DESCARTADO: índice `(cod_cliente, cod_vendedor, cod_segmento,
 * data_ultima_compra)` para cobrir os joins. Rendeu de forma instável (754-1.492 ms
 * contra 1.213-1.905 ms) e não valeu mais 10 MB de índice. Se um dia isto incomodar, o
 * caminho medido é outro: reduzir a uma linha por cliente ANTES dos joins, usando a
 * filial-âncora (o `ancora_id` que `CarteiraController::resumoDosCodigos()` já calcula)
 * — mas aí a aderência passa a ser a da âncora, e deixa de bater com o filtro
 * `?aderencia=`, que casa qualquer filial.
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

        /*
         * PASSO 1 — uma linha por CLIENTE (`cod_cliente`), não por filial.
         *
         * 🚨 Trocar o `COUNT(*)` por `COUNT(DISTINCT cod_cliente)` no agrupamento final
         * NÃO resolveria, e o erro seria silencioso: um cliente com uma filial ativa e
         * outra inativa apareceria nos DOIS grupos, cada um contando 1, e a soma das
         * quebras passaria do total de clientes. O card mostraria partes que não fecham
         * com o próprio todo.
         *
         * Por isso a redução acontece ANTES da classificação: primeiro cada cliente vira
         * uma linha com o seu estado consolidado, depois essas linhas é que são contadas.
         */
        $porCliente = (clone $query)
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
                clientes.cod_cliente,
                MAX(clientes.data_ultima_compra) as ultima_compra,
                MAX(CASE WHEN segmentos_vendedor.id IS NOT NULL THEN 1 ELSE 0 END) as alguma_dentro,
                MAX(CASE WHEN EXISTS (SELECT 1 FROM segmentos_vendedor sv2 WHERE sv2.cod_vendedor = clientes.cod_vendedor) THEN 1 ELSE 0 END) as vendedor_tem_segmento
            ')
            ->groupBy('clientes.cod_cliente');

        /*
         * PASSO 2 — classifica e conta os clientes.
         *
         * ⚠️ "Dentro do segmento" é QUALQUER filial dentro, e não o segmento da âncora
         * (que era o desenho original). O motivo é coerência com o que o clique faz: o
         * filtro `?aderencia=dentro` lista o cliente se qualquer filial casar, então
         * contá-lo pela âncora faria o card dizer um número e a lista entregar outro.
         * Os dois têm que bater em todos os casos.
         *
         * ⚠️ A data é a MAIS RECENTE entre as filiais — a mesma regra da listagem
         * agrupada. Se divergirem, um cliente aparece "ativo" na tabela e "inativo" no
         * card logo acima, na mesma tela.
         */
        $linhas = \DB::query()
            ->fromSub($porCliente, 'clientes_agrupados')
            ->selectRaw('
                CASE
                    WHEN ultima_compra >= ? THEN \'ativo\'
                    WHEN ultima_compra >= ? THEN \'inativando\'
                    ELSE \'inativo\'
                END as status_carteira,
                CASE
                    WHEN vendedor_tem_segmento = 0 THEN \'sem_segmento\'
                    WHEN alguma_dentro = 1 THEN \'dentro\'
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
