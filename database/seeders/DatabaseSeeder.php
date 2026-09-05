<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Usuários, clientes, faturamento, pedidos, leads, produtos e orçamentos reais não
     * vêm daqui — depois de rodar isto, rode `php artisan legado:import-usuarios`,
     * `legado:import-clientes`, `legado:import-faturamento`, `legado:import-pedidos`,
     * `legado:import-leads`, `legado:import-produtos` e (uma vez só)
     * `legado:import-orcamentos-historico` (precisam das credenciais LEGADO_DB_* em
     * .env, ver App\Console\Commands).
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SegmentoSeeder::class,
            // Depende do SegmentoSeeder: cria o par (segmento, família) que faltar.
            PotencialPesoSeeder::class,
            FacaSeeder::class,
            MarketingWpFormularioSeeder::class,
        ]);

        // Os seeders abaixo dependem dos usuários reais já estarem importados
        // (php artisan legado:import-usuarios) — pulados se não houver vendedores.
        if (\App\Models\User::role(['vendedor', 'representante'])->exists()) {
            $this->call([
                MetaMensalSeeder::class,
                LigacaoSeeder::class,
                SugestaoSeeder::class,
                DataSyncStatusSeeder::class,
                ObservacaoSeeder::class,
                SegmentoVendedorSeeder::class,
            ]);
        }
    }
}
