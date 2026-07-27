<?php

namespace Database\Seeders;

use App\Models\Observacao;
use App\Models\User;
use Illuminate\Database\Seeder;

class ObservacaoSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = User::role(['vendedor', 'representante'])->inRandomOrder()->limit(60)->get();

        foreach ($usuarios as $i => $usuario) {
            $quantidade = fake()->numberBetween(1, 4);

            for ($j = 0; $j < $quantidade; $j++) {
                Observacao::create([
                    'user_id' => $usuario->id,
                    'cnpj' => fake()->numerify('##.###.###/####-##'),
                    'mensagem' => fake()->sentence(15),
                    'fixada' => $j === 0 && $i < 10,
                ]);
            }
        }
    }
}
