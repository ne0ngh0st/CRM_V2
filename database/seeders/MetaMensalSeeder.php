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
                $metaFaturamentoBase = fake()->numberBetween(20000, 200000);
                $metaVendaBase = fake()->numberBetween(500, 4000);

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
