<?php

namespace App\Exports;

use App\Models\Orcamento;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class OrcamentoExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
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
        return ['Cliente', 'CNPJ', 'Vendedor', 'Valor Total', 'Nível', 'Status', 'Validade', 'Criado em'];
    }

    /** @param  Orcamento  $orcamento */
    public function map($orcamento): array
    {
        return [
            $orcamento->cliente_nome,
            $orcamento->cliente_cnpj,
            $orcamento->user->display_name ?: $orcamento->user->name,
            (float) $orcamento->valor_total,
            match ($orcamento->nivel_aprovacao) {
                'supervisor' => 'Supervisor',
                'diretor' => 'Diretor',
                default => 'Nenhum',
            },
            match ($orcamento->status_gestor) {
                'aprovado' => 'Aprovado',
                'rejeitado' => 'Rejeitado',
                default => 'Pendente',
            },
            optional($orcamento->data_validade)->format('d/m/Y'),
            $orcamento->created_at->format('d/m/Y'),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
