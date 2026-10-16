<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Uma aba de planilha com título, cabeçalho e linhas prontas — para os Excel de várias abas
 * (`WithMultipleSheets`) que não precisam de formatação própria.
 *
 * WithStrictNullComparison: sem ele o zero sai como célula vazia ("0 clientes ativos").
 */
class AbaSimples implements FromArray, WithHeadings, WithStrictNullComparison, WithTitle
{
    /**
     * @param  list<string>  $cabecalho
     * @param  list<list<mixed>>  $linhas
     */
    public function __construct(
        private readonly string $titulo,
        private readonly array $cabecalho,
        private readonly array $linhas,
    ) {
    }

    public function array(): array
    {
        return $this->linhas;
    }

    public function headings(): array
    {
        return $this->cabecalho;
    }

    public function title(): string
    {
        return $this->titulo;
    }
}
