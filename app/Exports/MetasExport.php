<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha do ranking de metas.
 *
 * ⚠️ Leva TODAS as colunas, independente da aba aberta na tela — a planilha é para
 * análise fora do sistema, e fazê-la seguir a aba obrigaria um conceito de interface a
 * atravessar a fila de exportação.
 *
 * ⚠️ Diverge da tela de propósito em dois pontos: "Falta vender" negativa sai como número
 * com sinal (a tela escreve "Coberto" — planilha não editorializa, e número negativo é
 * analisável); e em vez de linhas de subtotal há a coluna "Equipe", porque subtotal no meio
 * dos dados quebra tabela dinâmica e filtro.
 *
 * `headings()` e `array()` são duas listas paralelas mantidas à mão — há teste conferindo
 * que têm o mesmo tamanho.
 */
class MetasExport implements FromArray, WithHeadings
{
    /** @param  list<array<string, mixed>>  $linhas */
    public function __construct(private readonly array $linhas)
    {
    }

    public function array(): array
    {
        return array_map(fn (array $l) => [
            $l['nome'],
            $l['codVendedor'],
            $l['equipe'] ?? '—',
            $l['fatRealizado'],
            $l['fatMeta'],
            $l['fatPct'] !== null ? $l['fatPct'].'%' : '—',
            $l['emAberto'] ?? '—',
            $l['faltaVender'] ?? '—',
            $l['vendaRealizado'],
            $l['vendaMeta'],
            $l['vendaPct'] !== null ? $l['vendaPct'].'%' : '—',
        ], $this->linhas);
    }

    public function headings(): array
    {
        return [
            'Vendedor', 'Código', 'Equipe',
            'Fat. realizado', 'Fat. meta', 'Fat. %', 'Em aberto (hoje)', 'Falta vender',
            'Venda realizado', 'Venda meta', 'Venda %',
        ];
    }
}
