<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha para o Cadastro: filiais cadastradas no TOTVS fora do segmento da própria
 * empresa. As linhas vêm de `UniversoDoSegmento::filiaisForaDoSegmento()`.
 *
 * `headings()` e `map()` são listas paralelas mantidas à mão; há teste conferindo o tamanho.
 */
class FiliaisForaDoSegmentoExport implements FromArray, WithHeadings
{
    /** @param  list<array<string, mixed>>  $linhas */
    public function __construct(private readonly array $linhas)
    {
    }

    public function array(): array
    {
        return array_map(fn (array $l) => $this->map($l), $this->linhas);
    }

    public function headings(): array
    {
        return [
            'Empresa (raiz do CNPJ)', 'Segmento da empresa', 'Filiais no segmento da empresa', 'Filiais da empresa',
            'Conferir (empate)', 'Cód. cliente', 'Loja', 'CNPJ da filial', 'Razão social', 'Nome fantasia',
            'Segmento cadastrado (a corrigir)', 'Situação na Receita', 'Última compra', 'Cód. vendedor',
        ];
    }

    /** @param  array<string, mixed>  $l */
    public function map(array $l): array
    {
        return [
            $l['empresa'],
            $l['segmento_empresa'],
            (int) $l['filiais'],
            (int) $l['total'],
            // Empate: o segmento da empresa saiu pela matriz, não pela maioria — pode estar errado.
            (int) $l['empate'] > 1 ? 'Sim' : '',
            $l['cod_cliente'],
            $l['loja'],
            $l['cnpj'] ?? '',
            $l['razao_social'] ?? '',
            $l['nome_fantasia'] ?? '',
            $l['segmento_cadastrado'],
            $l['situacao'] ?? 'Não verificada',
            $l['data_ultima_compra'] ? Carbon::parse($l['data_ultima_compra'])->format('d/m/Y') : '',
            $l['cod_vendedor'] ?? '',
        ];
    }
}
