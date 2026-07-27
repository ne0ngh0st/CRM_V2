<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrcamentoSeeder extends Seeder
{
    private const PRODUTOS = [
        ['cod' => 'BOB001', 'desc' => 'Bobina Térmica 80x40'],
        ['cod' => 'ETQ010', 'desc' => 'Etiqueta Adesiva 100x50'],
        ['cod' => 'TCK005', 'desc' => 'Termoticket 57mm'],
        ['cod' => 'A4-075', 'desc' => 'Papel A4 75g'],
        ['cod' => 'RFID02', 'desc' => 'Tag RFID UHF'],
    ];

    public function run(): void
    {
        $gestores = User::role(['admin', 'diretor', 'supervisor'])->get();

        User::role(['vendedor', 'representante'])
            ->with('vendedorPerfil')
            ->get()
            ->each(function (User $user) use ($gestores) {
                $clienteDaCarteira = Cliente::query()->where('cod_vendedor', $user->vendedorPerfil?->cod_vendedor)->inRandomOrder()->first();

                foreach (range(1, fake()->numberBetween(1, 6)) as $i) {
                    $descontoMax = fake()->randomFloat(2, 0, 25);
                    $nivel = match (true) {
                        $descontoMax < 10 => 'nenhum',
                        $descontoMax <= 15 => 'supervisor',
                        default => 'diretor',
                    };

                    $desfecho = $nivel === 'nenhum' ? 'aprovado' : fake()->randomElement(['pendente', 'pendente', 'pendente', 'aprovado', 'aprovado', 'rejeitado']);
                    $aprovadoPor = $desfecho === 'aprovado' && $nivel !== 'nenhum' ? $gestores->random() : null;

                    $orcamento = Orcamento::create([
                        'user_id' => $user->id,
                        'cliente_nome' => $clienteDaCarteira->razao_social ?? fake()->company(),
                        'cliente_cnpj' => $clienteDaCarteira->cnpj ?? fake()->numerify('##.###.###/####-##'),
                        'cliente_contato' => fake()->name(),
                        'forma_pagamento' => fake()->randomElement(['Boleto 28 dias', 'Boleto 30/45/60', 'Pix à vista', 'Cartão de crédito']),
                        'valor_total' => 0,
                        'data_validade' => now()->addDays(fake()->numberBetween(7, 30)),
                        'desconto_pct_max' => $descontoMax,
                        'nivel_aprovacao' => $nivel,
                        'status_gestor' => $desfecho,
                        'aprovado_por_id' => $aprovadoPor?->id,
                        'aprovado_em' => $aprovadoPor ? now()->subDays(fake()->numberBetween(0, 5)) : null,
                        'motivo_rejeicao' => $desfecho === 'rejeitado' ? fake()->sentence(8) : null,
                    ]);

                    $valorTotal = 0;
                    foreach (range(1, fake()->numberBetween(1, 4)) as $j) {
                        $produto = fake()->randomElement(self::PRODUTOS);
                        $precoTabela = fake()->randomFloat(2, 5, 300);
                        $quantidade = fake()->numberBetween(10, 500);
                        $descontoItem = fake()->randomFloat(2, 0, $descontoMax);
                        $valorUnitario = round($precoTabela * (1 - $descontoItem / 100), 2);
                        $valorItem = round($valorUnitario * $quantidade, 2);
                        $valorTotal += $valorItem;

                        OrcamentoItem::create([
                            'orcamento_id' => $orcamento->id,
                            'tipo_item' => $produto['desc'],
                            'cod_produto' => $produto['cod'],
                            'descricao' => $produto['desc'],
                            'quantidade' => $quantidade,
                            'valor_unitario' => $valorUnitario,
                            'valor_total' => $valorItem,
                            'preco_tabela' => $precoTabela,
                        ]);
                    }

                    $orcamento->update(['valor_total' => $valorTotal]);
                }
            });
    }
}
