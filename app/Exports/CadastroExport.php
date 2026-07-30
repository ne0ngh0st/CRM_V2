<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CadastroExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    public function __construct(
        private readonly Builder $query,
        private readonly string $recurso,
    ) {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return match ($this->recurso) {
            'bobina' => ['#', 'Data', 'Título TOTVS', 'Nomenclatura', 'Qtd Caixa', 'Estoque Segurança', 'Status'],
            'etiqueta' => ['#', 'Data', 'Título TOTVS', 'Nomenclatura', 'Medidas', 'Saída', 'Status'],
            'cliente' => ['#', 'Data', 'CNPJ', 'Razão Social', 'Segmento', 'Status'],
            'lead' => ['#', 'Data', 'Razão Social', 'CNPJ', 'Telefone', 'E-mail', 'Status'],
        };
    }

    public function map($row): array
    {
        return match ($this->recurso) {
            'bobina' => [
                $row->id,
                $row->created_at?->format('d/m/Y H:i'),
                $row->titulo_padronizado,
                $row->nomenclatura,
                $row->quantidade_caixa,
                $row->estoque_seguranca_sn,
                $row->status,
            ],
            'etiqueta' => [
                $row->id,
                $row->created_at?->format('d/m/Y H:i'),
                $row->titulo_padronizado,
                $row->nomenclatura,
                $row->medidas,
                $row->saida_rolo,
                $row->status,
            ],
            'cliente' => [
                $row->id,
                $row->created_at?->format('d/m/Y H:i'),
                $row->cnpj_faturamento,
                $row->razao_social,
                $row->segmento_atuacao,
                $row->status,
            ],
            'lead' => [
                $row->id,
                $row->created_at?->format('d/m/Y H:i'),
                $row->razao_social,
                $row->cnpj,
                $row->telefone,
                $row->email,
                $row->status,
            ],
        };
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
