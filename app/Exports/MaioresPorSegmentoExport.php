<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha do Resumo da Visão Diretor → Maiores por Segmento: uma linha por segmento, com
 * os mesmos números da tela (`UniversoDoSegmento::resumo()`) — a planilha nunca recalcula.
 *
 * `headings()` e `array()` são listas paralelas mantidas à mão; há teste conferindo o
 * tamanho das duas.
 */
class MaioresPorSegmentoExport implements FromArray, WithHeadings
{
    /** @param  list<array<string, mixed>>  $segmentos  saída de `UniversoDoSegmento::resumo()` com o especialista */
    public function __construct(private readonly array $segmentos)
    {
    }

    public function array(): array
    {
        return array_map(fn (array $s) => [
            $s['nome'],
            $s['especialista']['nome'] ?? '',
            $s['clientes'],
            $s['ativo'],
            $s['inativando'],
            $s['inativo'],
            $s['leads'],
        ], $this->segmentos);
    }

    public function headings(): array
    {
        return ['Segmento', 'Especialista', 'Clientes', 'Ativos', 'Perdendo', 'A trabalhar', 'Leads'];
    }
}
