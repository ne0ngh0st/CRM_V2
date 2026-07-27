<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    public function run(): void
    {
        $vendedores = User::role(['vendedor', 'representante'])
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereNotNull('cod_vendedor'))
            ->with('vendedorPerfil')
            ->get();

        if ($vendedores->isEmpty()) {
            return;
        }

        $segmentos = ['SUPERMERCADISTA', 'DROGARIAS', 'ORGAO PUBLICO', 'REDE DE LOJAS', 'INDUSTRIA'];
        $estados = ['SP', 'RJ', 'MG', 'PR', 'SC', 'RS', 'BA'];
        $cidades = [
            'SP' => ['São Paulo', 'Campinas', 'Santos'],
            'RJ' => ['Rio de Janeiro', 'Niterói'],
            'MG' => ['Belo Horizonte', 'Uberlândia'],
            'PR' => ['Curitiba', 'Londrina'],
            'SC' => ['Florianópolis', 'Joinville'],
            'RS' => ['Porto Alegre', 'Caxias do Sul'],
            'BA' => ['Salvador', 'Feira de Santana'],
        ];

        $n = 0;
        foreach ($vendedores->take(40) as $user) {
            $cod = $user->vendedorPerfil->cod_vendedor;
            $qtd = fake()->numberBetween(2, 5);

            for ($i = 0; $i < $qtd; $i++) {
                $n++;
                $estado = fake()->randomElement($estados);
                $razao = fake()->company().' Ltda';

                Lead::query()->create([
                    'origem' => 'sistema',
                    'user_id' => null,
                    'cod_vendedor' => $cod,
                    'nome' => $razao,
                    'razao_social' => $razao,
                    'nome_fantasia' => fake()->optional(0.6)->company(),
                    'cnpj' => fake()->numerify('##.###.###/####-##'),
                    'email' => fake()->unique()->safeEmail(),
                    'telefone' => fake()->numerify('(11) 9####-####'),
                    'endereco' => fake()->streetAddress(),
                    'cidade' => fake()->randomElement($cidades[$estado]),
                    'estado' => $estado,
                    'segmento' => fake()->randomElement($segmentos),
                    'valor_estimado' => fake()->randomFloat(2, 5000, 250000),
                    'status' => fake()->randomElement(['ativo', 'ativo', 'ativo', 'inativo']),
                ]);
            }
        }

        // Alguns manuais extras
        foreach ($vendedores->take(10) as $user) {
            Lead::query()->create([
                'origem' => 'manual',
                'user_id' => $user->id,
                'cod_vendedor' => $user->vendedorPerfil->cod_vendedor,
                'nome' => fake()->company(),
                'razao_social' => fake()->company().' ME',
                'nome_fantasia' => null,
                'cnpj' => null,
                'email' => fake()->unique()->safeEmail(),
                'telefone' => fake()->numerify('(11) 9####-####'),
                'endereco' => fake()->streetAddress(),
                'cidade' => 'São Paulo',
                'estado' => 'SP',
                'segmento' => fake()->randomElement($segmentos),
                'valor_estimado' => fake()->optional()->randomFloat(2, 3000, 80000),
                'status' => 'ativo',
            ]);
        }
    }
}
