<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

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
            $l['fatRealizado'],
            $l['fatMeta'],
            $l['fatPct'] !== null ? $l['fatPct'].'%' : '—',
            $l['vendaRealizado'],
            $l['vendaMeta'],
            $l['vendaPct'] !== null ? $l['vendaPct'].'%' : '—',
        ], $this->linhas);
    }

    public function headings(): array
    {
        return ['Vendedor', 'Código', 'Fat. realizado', 'Fat. meta', 'Fat. %', 'Venda realizado', 'Venda meta', 'Venda %'];
    }
}
