<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha da Visão Diretor → Maiores por Segmento. Uma linha por conta, com os derivados
 * já calculados pelo `MaioresPorSegmentoResolver` — a planilha nunca recalcula nada.
 *
 * `headings()` e `array()` são listas paralelas mantidas à mão; há teste conferindo o
 * tamanho das duas.
 */
class MaioresPorSegmentoExport implements FromArray, WithHeadings
{
    /** Mesmo mapa do front (`ROTULOS_STATUS_CARTEIRA` + lead). Mudou lá, muda aqui. */
    private const ROTULOS_STATUS = [
        'ativo' => 'Ativo',
        'inativando' => 'Perdendo',
        'inativo' => 'A trabalhar',
        'lead' => 'Lead',
    ];

    /** @param  list<array<string, mixed>>  $segmentos  saída `segmentos` do resolver */
    public function __construct(private readonly array $segmentos)
    {
    }

    public function array(): array
    {
        $linhas = [];

        foreach ($this->segmentos as $segmento) {
            foreach ($segmento['contas'] as $c) {
                $linhas[] = [
                    $segmento['nome'],
                    $segmento['especialista']['nome'] ?? '',
                    $c['nome'],
                    $c['uf'] ?? '',
                    $c['filiaisMercado'] ?? '',
                    $c['lojas'],
                    $c['clientes'],
                    $c['penetracao'] !== null ? round($c['penetracao'] * 100, 1).'%' : '',
                    self::ROTULOS_STATUS[$c['status']] ?? $c['status'],
                    $c['ultimaCompra'] ?? '',
                    implode(' · ', array_column($c['atendimento'], 'nome')),
                    $c['fat12m'],
                    $c['fat12mAnterior'],
                    $c['site'] ?? '',
                    $c['observacao'] ?? '',
                ];
            }
        }

        return $linhas;
    }

    public function headings(): array
    {
        return [
            'Segmento', 'Especialista', 'Conta', 'UF', 'Filiais (mercado)', 'Nossas lojas',
            'Clientes', 'Penetração', 'Status', 'Última compra', 'Atendimento',
            'Fat. 12 meses', 'Fat. 12 meses anteriores', 'Site', 'Observação',
        ];
    }
}
