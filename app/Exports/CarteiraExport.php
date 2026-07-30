<?php

namespace App\Exports;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\VendedorPerfil;
use App\Services\Carteira\ClienteStatusResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CarteiraExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    private Collection $nomesPorCodVendedor;

    private Collection $segmentosPorVendedor;

    private Collection $nomePorCodigo;

    public function __construct(
        private readonly Builder $query,
        private readonly ClienteStatusResolver $statusResolver,
    ) {
        $this->nomesPorCodVendedor = VendedorPerfil::query()
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $this->segmentosPorVendedor = SegmentoVendedor::query()
            ->with('segmento')
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn ($grupo) => $grupo->pluck('segmento.codigo')->all());

        $this->nomePorCodigo = Segmento::pluck('nome', 'codigo');
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Cliente', 'CNPJ', 'Vendedor', 'Estado', 'Segmento', 'Status', 'Aderência', 'Última Compra'];
    }

    /** @param  Cliente  $cliente */
    public function map($cliente): array
    {
        $segmentosVendedor = $this->segmentosPorVendedor[$cliente->cod_vendedor] ?? [];
        $aderencia = match (true) {
            empty($segmentosVendedor) => 'Sem segmento definido',
            $cliente->cod_segmento && in_array($cliente->cod_segmento, $segmentosVendedor, true) => 'No segmento',
            default => 'Fora do segmento',
        };
        $status = $this->statusResolver->statusPara($cliente->data_ultima_compra, now());

        return [
            $cliente->razao_social,
            $cliente->cnpj,
            $this->nomesPorCodVendedor[$cliente->cod_vendedor] ?? $cliente->cod_vendedor,
            $cliente->estado,
            $cliente->cod_segmento ? ($this->nomePorCodigo[$cliente->cod_segmento] ?? $cliente->cod_segmento) : null,
            match ($status) {
                'ativo' => 'Ativo',
                'inativando' => 'Inativando',
                default => 'Inativo',
            },
            $aderencia,
            optional($cliente->data_ultima_compra)->format('d/m/Y'),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
