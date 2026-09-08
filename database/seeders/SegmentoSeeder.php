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
 *
 * ⚠️ `peso_potencial` é a escala 0-20 entregue pela diretoria em 08/09/2026 e alimenta a
 * coluna Potencial do card "Segmentos Atendidos". Os mesmos valores estão na migration
 * `add_peso_potencial_to_segmentos_table`, que é quem alcança dev e produção (bancos já
 * semeados); aqui eles existem para banco novo e para a suíte nascerem com o dado real.
 * Se a diretoria mandar pesos novos, os dois lugares mudam juntos — é o preço de ainda não
 * haver tela de edição.
 */
class SegmentoSeeder extends Seeder
{
    private const SEGMENTOS = [
        ['codigo' => '101', 'nome' => 'SUPERMERCADISTA', 'peso_potencial' => 20],
        ['codigo' => '103', 'nome' => 'ORGAO PUBLICO', 'peso_potencial' => 0],
        ['codigo' => '104', 'nome' => 'REVENDA', 'peso_potencial' => 20],
        ['codigo' => '105', 'nome' => 'CORPORATIVO', 'peso_potencial' => 0],
        ['codigo' => '106', 'nome' => 'TRANSPORTE', 'peso_potencial' => 0],
        ['codigo' => '107', 'nome' => 'AUTOMOTIVO PEÇAS E LOCADORAS', 'peso_potencial' => 0],
        ['codigo' => '108', 'nome' => 'REDE DE LOJAS', 'peso_potencial' => 10],
        ['codigo' => '109', 'nome' => 'DROGARIAS', 'peso_potencial' => 8],
        ['codigo' => '111', 'nome' => 'CORPORATIVO EDUCACIONAL', 'peso_potencial' => 0],
        ['codigo' => '112', 'nome' => 'ALIMENTACAO', 'peso_potencial' => 10],
        ['codigo' => '113', 'nome' => 'ESTACIONAMENTOS', 'peso_potencial' => 0],
        ['codigo' => '114', 'nome' => 'POSTOS E CONVENIENCIAS', 'peso_potencial' => 8],
        ['codigo' => '115', 'nome' => 'MAGAZINES', 'peso_potencial' => 10],
        ['codigo' => '116', 'nome' => 'COSMETICOS', 'peso_potencial' => 8],
        ['codigo' => '117', 'nome' => 'CORPORATIVO SAUDE', 'peso_potencial' => 0],
        ['codigo' => '118', 'nome' => 'PEDAGIO', 'peso_potencial' => 0],
        ['codigo' => '119', 'nome' => 'ENTRETENIMENTO', 'peso_potencial' => 0],
        ['codigo' => '120', 'nome' => 'CONSTRUCAO', 'peso_potencial' => 8],
        ['codigo' => '121', 'nome' => 'FABRICANTES DE EQUIPAMENTOS', 'peso_potencial' => 0],
        ['codigo' => '122', 'nome' => 'PET SHOP', 'peso_potencial' => 5],
        ['codigo' => '123', 'nome' => 'CORPORATIVO FINANCEIRO', 'peso_potencial' => 0],
        ['codigo' => '124', 'nome' => 'LOGISTICA', 'peso_potencial' => 0],
        ['codigo' => '125', 'nome' => 'E-COMMERCE', 'peso_potencial' => 5],
    ];

    public function run(): void
    {
        foreach (self::SEGMENTOS as $segmento) {
            Segmento::updateOrCreate(['codigo' => $segmento['codigo']], $segmento);
        }
    }
}
