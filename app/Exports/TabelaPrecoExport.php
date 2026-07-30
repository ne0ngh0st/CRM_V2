<?php

namespace App\Exports;

use App\Models\Produto;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TabelaPrecoExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    public function __construct(private readonly Builder $query)
    {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Código', 'Descrição', 'Categoria', 'Unidade', 'Preço Tabela'];
    }

    /** @param  Produto  $produto */
    public function map($produto): array
    {
        return [
            $produto->cod_produto,
            $produto->descricao,
            $produto->categoria,
            $produto->unidade,
            $produto->preco_tabela !== null ? (float) $produto->preco_tabela : null,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
