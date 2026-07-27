<?php

namespace Database\Seeders;

use App\Models\DataSyncStatus;
use Illuminate\Database\Seeder;

class DataSyncStatusSeeder extends Seeder
{
    public function run(): void
    {
        DataSyncStatus::updateOrCreate(['tabela' => 'faturamentos'], ['last_synced_at' => now()->subHours(4)]);
        DataSyncStatus::updateOrCreate(['tabela' => 'ligacoes'], ['last_synced_at' => now()->subMinutes(30)]);
    }
}
