<?php

namespace App\Exports;

use App\Services\VisaoDiretor\UniversoDoSegmento;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha da tabela de um segmento (Visão Diretor → Maiores por Segmento): clientes e
 * leads, na ordem e com os filtros da tela. As linhas vêm prontas de
 * `UniversoDoSegmento::tudo()` — a planilha nunca recalcula nada.
 *
 * `headings()` e `map()` são listas paralelas mantidas à mão; há teste conferindo o tamanho.
 */
class ClientesLeadsSegmentoExport implements FromArray, WithHeadings
{
    /** Mesmo mapa do front (`ROTULOS_STATUS_CONTA`). Mudou lá, muda aqui. */
    private const ROTULOS_STATUS = [
        'ativo' => 'Ativo',
        'inativando' => 'Perdendo',
        'inativo' => 'A trabalhar',
        'lead' => 'Lead',
    ];

    /**
     * Guarda só o recorte: as linhas são montadas em `array()`, quando o arquivo é gerado
     * (na fila, se passar do limite) — nunca dentro da requisição que só precisa contar.
     *
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     */
    public function __construct(
        private readonly string $segmento,
        private readonly array $filtros,
    ) {
    }

    public function array(): array
    {
        return array_map(
            fn (array $l) => $this->map($l),
            app(UniversoDoSegmento::class)->tudo($this->segmento, $this->filtros),
        );
    }

    public function headings(): array
    {
        return ['Razão social', 'Nome fantasia', 'Tipo', 'CNPJ', 'Grupo', 'UF', 'Filiais', 'Status', 'Vendedor'];
    }

    /** @param  array<string, mixed>  $l  uma linha de `UniversoDoSegmento::tudo()` */
    public function map(array $l): array
    {
        return [
            $l['nome'],
            $l['nomeFantasia'] ?? '',
            $l['tipo'] === 'lead' ? 'Lead' : 'Cliente',
            $l['documento'] ?? '',
            $l['grupo'] ?? '',
            $l['uf'] ?? '',
            $l['filiais'] ?? '',
            self::ROTULOS_STATUS[$l['status']] ?? $l['status'],
            $l['vendedor'] ?? '',
        ];
    }
}
