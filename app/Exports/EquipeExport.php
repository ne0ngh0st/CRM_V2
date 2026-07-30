<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EquipeExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
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
        return ['Usuário', 'E-mail', 'Perfil', 'Status', 'Estado', 'Cód. Vendedor', 'Cód. Supervisor', 'Último Login'];
    }

    /** @param  User  $usuario */
    public function map($usuario): array
    {
        return [
            $usuario->display_name ?: $usuario->name,
            $usuario->email,
            $usuario->getRoleNames()->first(),
            $usuario->is_active ? 'Ativo' : 'Inativo',
            $usuario->estado,
            $usuario->vendedorPerfil?->cod_vendedor,
            $usuario->vendedorPerfil?->cod_super,
            optional($usuario->last_login_at)->format('d/m/Y H:i'),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
