<?php

namespace App\Exports;

use App\Models\Pedido;
use App\Models\VendedorPerfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PedidoEmitidoExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
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
        return ['Pedido', 'Cliente', 'CNPJ', 'Vendedor', 'Data Pedido', 'Faturamento', 'Valor Total', 'Status', 'Itens'];
    }

    /** @param  Pedido  $pedido */
    public function map($pedido): array
    {
        return [
            $pedido->numero_pedido,
            $pedido->cliente?->razao_social,
            $pedido->cliente?->cnpj,
            $this->nomesPorCodVendedor[$pedido->cod_vendedor] ?? $pedido->cod_vendedor,
            optional($pedido->data_pedido)->format('d/m/Y'),
            optional($pedido->data_faturamento)->format('d/m/Y') ?: 'Em aberto',
            (float) $pedido->valor_total,
            $pedido->status,
            $pedido->itens_count,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
