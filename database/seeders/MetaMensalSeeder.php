<?php

namespace Database\Seeders;

use App\Models\MetaMensal;
use App\Models\VendedorPerfil;
use Illuminate\Database\Seeder;

class MetaMensalSeeder extends Seeder
{
    public function run(): void
    {
        $ano = (int) now()->year;

        // cod_vendedor não é unique entre usuários (pode ser compartilhado), então a meta
        // precisa ser gerada por código único, não por linha de vendedor_perfis.
        VendedorPerfil::query()
            ->whereHas('user', fn ($q) => $q->role(['vendedor', 'representante']))
            ->pluck('cod_vendedor')
            ->unique()
            ->each(function (string $codVendedor) use ($ano) {
                /*
                 * ⚠️ AS DUAS METAS SÃO R$, E NA MESMA ORDEM DE GRANDEZA. Até 2026-09-04 a meta
                 * de venda era sorteada entre 500 e 4.000 — provavelmente escrita pensando em
                 * QUANTIDADE de pedidos — enquanto a de faturamento ia até 200 mil. Como o
                 * realizado de venda soma `pedidos.valor_total` (milhões), o gauge de venda em
                 * desenvolvimento mostrava percentuais de cinco dígitos e parecia defeito de
                 * cálculo. Seed em escala errada esconde bug de verdade: foi assim que os
                 * segmentos inventados mascararam a aderência quebrada em julho.
                 *
                 * Venda costuma superar faturamento no mesmo mês (o pedido entra antes da
                 * nota), daí a meta de venda nascer um pouco acima da de faturamento.
                 */
                $metaFaturamentoBase = fake()->numberBetween(20000, 200000);
                $metaVendaBase = (int) round($metaFaturamentoBase * fake()->randomFloat(2, 0.95, 1.25));

                foreach (range(1, 12) as $mes) {
                    MetaMensal::create([
                        'cod_vendedor' => $codVendedor,
                        'ano' => $ano,
                        'mes' => $mes,
                        'tipo' => 'faturamento',
                        'valor_meta' => $metaFaturamentoBase * fake()->randomFloat(2, 0.9, 1.1),
                    ]);

                    MetaMensal::create([
                        'cod_vendedor' => $codVendedor,
                        'ano' => $ano,
                        'mes' => $mes,
                        'tipo' => 'venda',
                        'valor_meta' => $metaVendaBase * fake()->randomFloat(2, 0.9, 1.1),
                    ]);
                }
            });
    }
}
