<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LigacaoSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = User::role(['vendedor', 'representante'])->get(['id']);
        $agora = now();
        $linhas = [];

        foreach ($usuarios as $usuario) {
            $quantidade = fake()->numberBetween(15, 60);

            for ($i = 0; $i < $quantidade; $i++) {
                $status = fake()->randomElement(['finalizada', 'finalizada', 'finalizada', 'cancelada', 'ativa']);

                $linhas[] = [
                    'usuario_id' => $usuario->id,
                    'cliente_nome' => fake()->company(),
                    'tipo_contato' => fake()->randomElement(['telefonica', 'telefonica', 'whatsapp', 'presencial', 'email']),
                    'status' => $status,
                    'data_ligacao' => fake()->dateTimeBetween('-6 months', 'now'),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        foreach (array_chunk($linhas, 500) as $chunk) {
            DB::table('ligacoes')->insert($chunk);
        }
    }
}
