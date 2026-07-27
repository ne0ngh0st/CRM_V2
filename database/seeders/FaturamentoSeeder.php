<?php

namespace Database\Seeders;

use App\Models\VendedorPerfil;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FaturamentoSeeder extends Seeder
{
    private const PRODUTOS = [
        ['cod' => 'BOB001', 'desc' => 'Bobina Térmica 80x40', 'segmento' => 'Bobina'],
        ['cod' => 'ETQ010', 'desc' => 'Etiqueta Adesiva 100x50', 'segmento' => 'Etiqueta'],
        ['cod' => 'TCK005', 'desc' => 'Termoticket 57mm', 'segmento' => 'Termoticket'],
        ['cod' => 'A4-075', 'desc' => 'Papel A4 75g', 'segmento' => 'A4'],
        ['cod' => 'RFID02', 'desc' => 'Tag RFID UHF', 'segmento' => 'RFID'],
    ];

    public function run(): void
    {
        $anoAtual = (int) now()->year;
        $mesAtual = (int) now()->month;

        // cod_vendedor não é unique entre usuários (pode ser compartilhado) — gera
        // faturamento por código único, senão duplicaria receita pro mesmo código.
        $codsVendedor = VendedorPerfil::query()
            ->whereHas('user', fn ($q) => $q->role(['vendedor', 'representante']))
            ->pluck('cod_vendedor')
            ->unique();

        $linhas = [];
        $agora = now();

        foreach ($codsVendedor as $codVendedor) {
            $metaBase = fake()->numberBetween(20000, 200000);

            foreach ([$anoAtual - 1, $anoAtual] as $ano) {
                $ultimoMes = $ano === $anoAtual ? $mesAtual : 12;

                foreach (range(1, $ultimoMes) as $mes) {
                    $metaMes = DB::table('metas_mensais')
                        ->where('cod_vendedor', $codVendedor)
                        ->where('ano', $anoAtual)
                        ->where('mes', $mes)
                        ->where('tipo', 'faturamento')
                        ->value('valor_meta') ?? $metaBase;

                    $totalMes = $metaMes * fake()->randomFloat(2, 0.55, 1.35);
                    $numLinhas = fake()->numberBetween(3, 7);
                    $pesos = array_map(fn () => fake()->randomFloat(2, 0.5, 2), range(1, $numLinhas));
                    $somaPesos = array_sum($pesos);

                    $cnpj = fake()->numerify('##.###.###/####-##');
                    $clienteNome = fake()->company();
                    $diasNoMes = Carbon::create($ano, $mes, 1)->daysInMonth;

                    foreach ($pesos as $peso) {
                        $produto = fake()->randomElement(self::PRODUTOS);
                        $valorTotal = round($totalMes * ($peso / $somaPesos), 2);
                        $quantidade = fake()->numberBetween(1, 200);

                        $linhas[] = [
                            'filial' => 1,
                            'nota_fiscal' => (string) fake()->unique()->numberBetween(100000, 999999),
                            'pedido' => (string) fake()->numberBetween(800000, 999999),
                            'data_emissao' => Carbon::create($ano, $mes, fake()->numberBetween(1, $diasNoMes))->toDateString(),
                            'cod_cliente' => (string) fake()->numberBetween(1000, 9999),
                            'cnpj' => $cnpj,
                            'cliente_nome' => $clienteNome,
                            'cod_vendedor' => $codVendedor,
                            'cod_produto' => $produto['cod'],
                            'produto_desc' => $produto['desc'],
                            'segmento' => $produto['segmento'],
                            'quantidade' => $quantidade,
                            'valor_unitario' => $quantidade > 0 ? round($valorTotal / $quantidade, 2) : 0,
                            'valor_total' => $valorTotal,
                            'created_at' => $agora,
                            'updated_at' => $agora,
                        ];
                    }
                }
            }
        }

        foreach (array_chunk($linhas, 500) as $chunk) {
            DB::table('faturamentos')->insert($chunk);
        }
    }
}
