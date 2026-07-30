<?php

namespace Database\Seeders;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * "Segmento" do cliente = setor/vertical (código real do TOTVS, ver
 * SegmentoSeeder — `cod_segmento` guarda o código, igual ao import real de
 * `legado:import-clientes`, nunca o nome). Pra dar sentido ao relatório de
 * aderência, ~70% dos clientes de cada vendedor caem dentro de um dos
 * segmentos que o próprio vendedor atende — o resto é "fora do segmento" de
 * propósito, pra os números de aderência não saírem 100%.
 */
class ClienteSeeder extends Seeder
{
    public function run(): void
    {
        $agora = now();
        $codCliente = 1000;
        $linhas = [];

        $todosCodigos = Segmento::pluck('codigo');

        $segmentosPorVendedor = SegmentoVendedor::query()
            ->with('segmento')
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn ($grupo) => $grupo->pluck('segmento.codigo')->all());

        // Clientes reais (têm faturamento) — deriva de combos (cod_vendedor, cnpj) já
        // existentes em `faturamentos`, então o status ativo/inativando/inativo (calculado
        // a partir de `data_ultima_compra`) sai coerente de graça, sem precisar inventar datas.
        // Em produção esse campo vem pronto do TOTVS junto com o resto do cadastro do
        // cliente (igual razao_social/estado) — aqui no seed local, copiamos a data máxima
        // de `faturamentos` só porque é a fonte fake que já temos, não porque essa tabela
        // vá ser consultada pela Carteira depois (não vai).
        $combos = DB::table('faturamentos')
            ->select('cod_vendedor', 'cnpj', 'cliente_nome')
            ->distinct()
            ->get();

        $ultimaCompraPorCnpj = DB::table('faturamentos')
            ->select('cnpj')
            ->selectRaw('MAX(data_emissao) as ultima_compra')
            ->groupBy('cnpj')
            ->pluck('ultima_compra', 'cnpj');

        foreach ($combos as $combo) {
            $segmentosVendedor = $segmentosPorVendedor[$combo->cod_vendedor] ?? [];
            $segmento = $segmentosVendedor && fake()->boolean(70)
                ? fake()->randomElement($segmentosVendedor)
                : $todosCodigos->diff($segmentosVendedor)->random();

            $linhas[] = [
                'cod_cliente' => (string) $codCliente++,
                'loja' => '01',
                'cnpj' => $combo->cnpj,
                'razao_social' => $combo->cliente_nome,
                'nome_fantasia' => $combo->cliente_nome,
                'cod_vendedor' => $combo->cod_vendedor,
                'cod_segmento' => $segmento,
                'estado' => fake()->stateAbbr(),
                'cep' => fake()->numerify('#####-###'),
                'telefone' => fake()->numerify('(##) #####-####'),
                'email' => fake()->companyEmail(),
                'data_ultima_compra' => $ultimaCompraPorCnpj[$combo->cnpj] ?? null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        // Clientes "frios" — nunca compraram, sem linha em `faturamentos`, pra dar
        // um contingente real de "inativo" na carteira (não só quem já esfriou).
        foreach ($segmentosPorVendedor->keys() as $codVendedor) {
            $segmentosVendedor = $segmentosPorVendedor[$codVendedor];
            $quantidade = fake()->numberBetween(1, 5);

            for ($i = 0; $i < $quantidade; $i++) {
                $segmento = fake()->boolean(70)
                    ? fake()->randomElement($segmentosVendedor)
                    : $todosCodigos->diff($segmentosVendedor)->random();

                $linhas[] = [
                    'cod_cliente' => (string) $codCliente++,
                    'loja' => '01',
                    'cnpj' => fake()->numerify('##.###.###/####-##'),
                    'razao_social' => fake()->company(),
                    'nome_fantasia' => fake()->company(),
                    'cod_vendedor' => $codVendedor,
                    'cod_segmento' => $segmento,
                    'estado' => fake()->stateAbbr(),
                    'cep' => fake()->numerify('#####-###'),
                    'telefone' => fake()->numerify('(##) #####-####'),
                    'email' => fake()->companyEmail(),
                    'data_ultima_compra' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        foreach (array_chunk($linhas, 500) as $chunk) {
            DB::table('clientes')->insert($chunk);
        }
    }
}
