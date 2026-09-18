<?php

namespace App\Services\VisaoDiretor;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dono ÚNICO da escrita em `faturamento_cliente_mensal` — o faturamento por cliente e mês
 * que a Visão Diretor lê em vez de agregar `faturamentos` ao vivo (~10 s por conta, ver a
 * migration `2026_09_18_110000`).
 *
 * Recalcula mês a mês com delete + insert, dentro de uma transação por mês: quem lê nunca
 * vê um mês pela metade. Valor derivado, então rodar de novo é sempre seguro.
 *
 * ⚠️ Invariante travada por teste: a soma do rollup em cada mês é IGUAL à soma de
 * `faturamentos` naquele mês. Se `faturamentos` for recarregado por um caminho que não
 * passa pelo `totvs:atualizar` (ex.: `legado:import-faturamento-arquivo --ano=`), rodar
 * `faturamento:rollup-mensal` para os meses tocados — senão a Visão Diretor mostra o
 * número antigo sem nada acusar.
 */
class FaturamentoMensalRollup
{
    /**
     * Recalcula os meses de `$desde` a `$ate` (inclusive; qualquer dia dentro do mês).
     *
     * @return int quantos meses foram recalculados
     */
    public function recalcular(CarbonImmutable $desde, CarbonImmutable $ate): int
    {
        $mes = $desde->startOfMonth();
        $ultimo = $ate->startOfMonth();
        $meses = 0;

        while ($mes->lessThanOrEqualTo($ultimo)) {
            $this->recalcularMes($mes);
            $mes = $mes->addMonth();
            $meses++;
        }

        return $meses;
    }

    /**
     * O que a rodada automática recalcula: mês corrente e anterior. O relatório FAT é
     * recorte de mês inteiro e o do dia 1º ainda pode trazer ajuste do mês que fechou.
     */
    public function recalcularRecentes(): int
    {
        $hoje = CarbonImmutable::today();

        return $this->recalcular($hoje->subMonthNoOverflow(), $hoje);
    }

    /**
     * ⚠️ `data_emissao` por INTERVALO, nunca `YEAR()`/`MONTH()` na coluna: envolver a coluna
     * numa função tira o índice `fat_data_valor_idx` do jogo (Regra de ouro nº 6). Com o
     * intervalo, um mês é um range scan de ~70 mil linhas.
     */
    private function recalcularMes(CarbonImmutable $mes): void
    {
        $inicio = $mes->startOfMonth();
        $fim = $mes->endOfMonth();

        DB::transaction(function () use ($inicio, $fim) {
            DB::table('faturamento_cliente_mensal')->where('mes', $inicio->toDateString())->delete();

            DB::statement(
                'INSERT INTO faturamento_cliente_mensal (cod_cliente, mes, valor_total, notas, calculado_em)
                 SELECT cod_cliente, ?, SUM(valor_total), COUNT(DISTINCT nota_fiscal), ?
                   FROM faturamentos
                  WHERE data_emissao BETWEEN ? AND ?
                    AND cod_cliente IS NOT NULL
                  GROUP BY cod_cliente',
                [$inicio->toDateString(), now(), $inicio->toDateString(), $fim->toDateString()],
            );
        });
    }

    /**
     * Quando o rollup foi recalculado pela última vez — a tela mostra isso, porque dado
     * velho não acende luz vermelha.
     *
     * Olha só o mês mais recente, que é sempre o último a ser recalculado; assim a consulta
     * usa o índice por `mes` em vez de varrer a tabela.
     */
    public function atualizadoEm(): ?CarbonImmutable
    {
        $mes = DB::table('faturamento_cliente_mensal')->max('mes');

        if ($mes === null) {
            return null;
        }

        $valor = DB::table('faturamento_cliente_mensal')->where('mes', $mes)->max('calculado_em');

        return $valor ? CarbonImmutable::parse($valor) : null;
    }
}
