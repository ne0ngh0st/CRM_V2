<?php

namespace Database\Seeders;

use App\Models\SegmentoVendedor;
use App\Models\VendedorPerfil;
use Illuminate\Database\Seeder;

/**
 * "Segmento" aqui é o setor/vertical do cliente (supermercadista, órgão público,
 * drogaria...) — cada vendedor é responsável por 1-2 setores. Não confundir com
 * produto (bobina/etiqueta/...), que é outra coisa (ver FaturamentoSeeder).
 *
 * Nomes reais tirados de `segmentos_referencia_2026` de produção (SUPERMERCADISTA
 * domina com longa distância — 136 de ~200 vendedores). Fora do pool de propósito:
 * "INATIVOS GERAL" e "PRIMEIRO CONTATO", que no legado são tipos especiais com
 * regra de match própria — não replicados aqui (ver CLAUDE.md).
 */
class SegmentoVendedorSeeder extends Seeder
{
    private const CURVAS = ['A', 'B', 'C'];

    public function run(): void
    {
        $pool = $this->poolPonderado();

        VendedorPerfil::query()
            ->whereHas('user', fn ($q) => $q->role(['vendedor', 'representante']))
            ->pluck('cod_vendedor')
            ->unique()
            ->each(function (string $codVendedor) use ($pool) {
                $segmentos = fake()->randomElements($pool, fake()->boolean(15) ? 2 : 1);

                foreach (array_unique($segmentos) as $segmento) {
                    SegmentoVendedor::create([
                        'cod_vendedor' => $codVendedor,
                        'segmento' => $segmento,
                        'curva_abc' => fake()->randomElement(self::CURVAS),
                    ]);
                }
            });
    }

    /** @return array<string> */
    private function poolPonderado(): array
    {
        // SUPERMERCADISTA repetido pra pesar a amostragem (~70% dos vendedores reais).
        return array_merge(
            array_fill(0, 10, 'SUPERMERCADISTA'),
            [
                'ORGAO PUBLICO', 'DROGARIAS', 'REDE DE LOJAS', 'AEROPORTOS', 'ALIMENTACAO',
                'REVENDA', 'TRANSPORTE DE CARGA', 'AUTOMOTIVO PECAS E LOCADORAS', 'BIONEXO',
                'CONSTRUCAO', 'CORPORATIVO', 'COSMETICOS E PERFUMARIA', 'E-COMMERCE',
                'ENTRETERIMENTO', 'ESTACIONAMENTOS', 'SUPRIMENTOS', 'TRANSPORTADORAS',
                'PET SHOP', 'POSTOS E CONVENIENCIAS',
            ],
        );
    }
}
