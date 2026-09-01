<?php

namespace App\Exports;

use App\Models\Lead;
use App\Models\VendedorPerfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeadExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    private Collection $nomesPorCodVendedor;

    public function __construct(private readonly Builder $query)
    {
        $this->nomesPorCodVendedor = VendedorPerfil::query()
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Lead', 'CNPJ', 'Vendedor', 'Estado', 'Segmento', 'Origem', 'Status', 'Valor estimado'];
    }

    /** @param  Lead  $lead */
    public function map($lead): array
    {
        return [
            $lead->razao_social ?: $lead->nome,
            $lead->cnpj,
            $this->nomesPorCodVendedor[$lead->cod_vendedor] ?? $lead->cod_vendedor,
            $lead->estado,
            $lead->segmento,
            Lead::rotuloOrigem((string) $lead->origem),
            match ($lead->status) {
                'ativo' => 'Ativo',
                'convertido' => 'Convertido',
                default => 'Inativo',
            },
            $lead->valor_estimado !== null ? (float) $lead->valor_estimado : null,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
