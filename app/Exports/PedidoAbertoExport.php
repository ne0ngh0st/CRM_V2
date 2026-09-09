<?php

namespace App\Exports;

use App\Models\Pedido;
use App\Services\Pedidos\StatusPedidoResolver;
use App\Models\VendedorPerfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PedidoAbertoExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    private Collection $nomesPorCodVendedor;

    private readonly StatusPedidoResolver $status;

    public function __construct(private readonly Builder $query)
    {
        $this->status = new StatusPedidoResolver;

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
        return ['Pedido', 'Cliente', 'CNPJ', 'Vendedor', 'Data Pedido', 'Previsão Faturamento', 'Valor Total', 'Status', 'Movimento no TOTVS', 'Movimento em', 'Itens'];
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
            optional($pedido->data_previsao_faturamento)->format('d/m/Y'),
            (float) $pedido->valor_total,
            /*
             * ⚠️ O RÓTULO, nunca o valor do enum. A planilha vai para fora do
             * sistema e ninguém de fora sabe o que é `bloqueio_estoque`. Vem do
             * mesmo resolver que alimenta a tela, então os dois nunca divergem.
             *
             * Movimento não reconhecido não vira texto inventado: a célula fica
             * vazia e o texto cru do TOTVS aparece na coluna ao lado.
             */
            $this->status->rotulo($pedido->status),
            $pedido->historico_totvs,
            optional($pedido->historico_em)->format('d/m/Y H:i'),
            $pedido->itens_count,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
