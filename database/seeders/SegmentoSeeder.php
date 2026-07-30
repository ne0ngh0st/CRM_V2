<?php

namespace Database\Seeders;

use App\Models\Segmento;
use Illuminate\Database\Seeder;

/**
 * Segmentos reais do TOTVS — código (`Segmento1`) + descrição (`Descricao1`) da
 * tabela `ultimo_faturamento` (espelho `autopel01_homolog`), confirmados por Tony
 * em 2026-07-29. Substitui a lista inventada que existia antes em
 * `SegmentoVendedor::SEGMENTOS` (nomes que não existem no TOTVS, tipo "BIONEXO"
 * ou "SUPRIMENTOS") — ver CLAUDE.md.
 *
 * Códigos 100/102/110 aparecem em `clientes.cod_segmento` (import real) mas não
 * têm descrição em nenhuma tabela do TOTVS acessível (nenhum cliente com esses
 * códigos tem faturamento histórico) — não inventados aqui, ficam sem match na
 * tabela `segmentos` (fallback pro código bruto na exibição).
 */
class SegmentoSeeder extends Seeder
{
    private const SEGMENTOS = [
        ['codigo' => '101', 'nome' => 'SUPERMERCADISTA'],
        ['codigo' => '103', 'nome' => 'ORGAO PUBLICO'],
        ['codigo' => '104', 'nome' => 'REVENDA'],
        ['codigo' => '105', 'nome' => 'CORPORATIVO'],
        ['codigo' => '106', 'nome' => 'TRANSPORTE'],
        ['codigo' => '107', 'nome' => 'AUTOMOTIVO PEÇAS E LOCADORAS'],
        ['codigo' => '108', 'nome' => 'REDE DE LOJAS'],
        ['codigo' => '109', 'nome' => 'DROGARIAS'],
        ['codigo' => '111', 'nome' => 'CORPORATIVO EDUCACIONAL'],
        ['codigo' => '112', 'nome' => 'ALIMENTACAO'],
        ['codigo' => '113', 'nome' => 'ESTACIONAMENTOS'],
        ['codigo' => '114', 'nome' => 'POSTOS E CONVENIENCIAS'],
        ['codigo' => '115', 'nome' => 'MAGAZINES'],
        ['codigo' => '116', 'nome' => 'COSMETICOS'],
        ['codigo' => '117', 'nome' => 'CORPORATIVO SAUDE'],
        ['codigo' => '118', 'nome' => 'PEDAGIO'],
        ['codigo' => '119', 'nome' => 'ENTRETENIMENTO'],
        ['codigo' => '120', 'nome' => 'CONSTRUCAO'],
        ['codigo' => '121', 'nome' => 'FABRICANTES DE EQUIPAMENTOS'],
        ['codigo' => '122', 'nome' => 'PET SHOP'],
        ['codigo' => '123', 'nome' => 'CORPORATIVO FINANCEIRO'],
        ['codigo' => '124', 'nome' => 'LOGISTICA'],
        ['codigo' => '125', 'nome' => 'E-COMMERCE'],
    ];

    public function run(): void
    {
        foreach (self::SEGMENTOS as $segmento) {
            Segmento::updateOrCreate(['codigo' => $segmento['codigo']], $segmento);
        }
    }
}
