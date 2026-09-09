<?php

namespace App\Exports;

use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\SegmentosDoVendedorResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class EquipeExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    /** @var Collection<string, Collection<int, \App\Models\Segmento>>|null */
    private ?Collection $segmentosPorCodVendedor = null;

    public function __construct(
        private readonly Builder $query,
        private readonly SegmentosDoVendedorResolver $segmentosDoVendedor,
    ) {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Usuário', 'E-mail', 'Perfil', 'Status', 'Estado', 'Cód. Vendedor', 'Cód. Supervisor', 'Segmentos', 'Último Login'];
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
            $this->segmentosDe($usuario->vendedorPerfil?->cod_vendedor),
            optional($usuario->last_login_at)->format('d/m/Y H:i'),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Mesma coluna "Segmentos" da tela de Equipe, pela MESMA fonte
     * (SegmentosDoVendedorResolver) — Regra de ouro nº 8: um segundo caminho para
     * responder "quais segmentos este vendedor atende" é o que faria o Excel divergir
     * da tela sozinho.
     *
     * ⚠️ O mapa é resolvido UMA vez para a exportação inteira, e não por linha: com
     * `map()` sendo chamado por usuário, uma consulta aqui viraria N+1 dentro de cada
     * chunk. São três queries no total (os códigos do escopo, os vínculos e os
     * segmentos), qualquer que seja o tamanho da lista — medido com os 134 usuários
     * reais do escopo admin: 3 queries, 51 ms.
     */
    private function segmentosDe(?string $codVendedor): string
    {
        if ($codVendedor === null || trim($codVendedor) === '') {
            return '';
        }

        $this->segmentosPorCodVendedor ??= $this->segmentosDoVendedor->porCodigo(
            VendedorPerfil::query()
                ->whereIn('user_id', (clone $this->query)->select('users.id'))
                ->pluck('cod_vendedor')
        );

        return ($this->segmentosPorCodVendedor[$codVendedor] ?? collect())
            ->pluck('nome')
            ->sort()
            ->implode(', ');
    }
}
