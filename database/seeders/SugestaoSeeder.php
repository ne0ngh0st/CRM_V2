<?php

namespace Database\Seeders;

use App\Models\Sugestao;
use App\Models\User;
use Illuminate\Database\Seeder;

class SugestaoSeeder extends Seeder
{
    public function run(): void
    {
        $autores = User::role(['vendedor', 'representante', 'assistente'])->inRandomOrder()->limit(10)->get();
        $admin = User::role('admin')->first();

        $categorias = ['Sistema', 'Relatórios', 'Atendimento', 'Outros'];

        foreach ($autores as $i => $autor) {
            $respondida = $i < 4;

            Sugestao::create([
                'user_id' => $autor->id,
                'categoria' => fake()->randomElement($categorias),
                'mensagem' => fake()->sentence(12),
                'status' => $respondida ? fake()->randomElement(['aprovada', 'implementada', 'rejeitada']) : fake()->randomElement(['pendente', 'em_analise']),
                'resposta_admin' => $respondida ? fake()->sentence(10) : null,
                'admin_respondeu_id' => $respondida ? $admin?->id : null,
                'visivel' => true,
            ]);
        }
    }
}
