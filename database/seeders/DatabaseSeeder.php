<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Os 202 usuários reais (comercial) não vêm daqui — depois de rodar isto,
     * rode `php artisan legado:import-usuarios` (precisa das credenciais
     * LEGADO_DB_* em .env, ver App\Console\Commands\ImportUsuariosLegado).
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
        ]);

        // Os seeders abaixo dependem dos usuários reais já estarem importados
        // (php artisan legado:import-usuarios) — pulados se não houver vendedores.
        if (\App\Models\User::role(['vendedor', 'representante'])->exists()) {
            $this->call([
                MetaMensalSeeder::class,
                FaturamentoSeeder::class,
                LigacaoSeeder::class,
                SugestaoSeeder::class,
                DataSyncStatusSeeder::class,
                ObservacaoSeeder::class,
                SegmentoVendedorSeeder::class,
                ClienteSeeder::class,
                LeadSeeder::class,
                ProdutoSeeder::class,
                OrcamentoSeeder::class,
                PedidoSeeder::class,
            ]);
        }
    }
}
