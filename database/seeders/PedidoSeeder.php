<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\VendedorPerfil;
use App\Services\Pedidos\StatusPedidoResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PedidoSeeder extends Seeder
{
    private const PRODUTOS = [
        ['cod' => 'BOB001', 'desc' => 'Bobina Térmica 80x40'],
        ['cod' => 'ETQ010', 'desc' => 'Etiqueta Adesiva 100x50'],
        ['cod' => 'TCK005', 'desc' => 'Termoticket 57mm'],
        ['cod' => 'A4-075', 'desc' => 'Papel A4 75g'],
        ['cod' => 'RFID02', 'desc' => 'Tag RFID UHF'],
    ];

    /**
     * Pares [etapa, frase do TOTVS] para o pedido ainda em aberto.
     *
     * ⚠️ A frase importa tanto quanto a etapa: a linha expandida da tela mostra o texto
     * cru, e um seed que gravasse só o status deixaria esse bloco vazio em dev — quem
     * fosse mexer nele acharia que está quebrado. Repetições fazem o peso, imitando a
     * distribuição real (em carga ~44%, bloqueio de estoque ~30%).
     *
     * O último par exercita de propósito o caminho do movimento NÃO reconhecido: é a
     * única forma de ver na tela de dev como fica um pedido sem pill.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const MOVIMENTOS_EM_ABERTO = [
        [StatusPedidoResolver::EM_CARGA, 'PEDIDO %s INCLUIDO NA CARGA 190017'],
        [StatusPedidoResolver::EM_CARGA, 'PEDIDO %s INCLUIDO NA CARGA 191474'],
        [StatusPedidoResolver::EM_CARGA, 'PEDIDO %s INCLUIDO NA CARGA 190916'],
        [StatusPedidoResolver::BLOQUEIO_ESTOQUE, 'PEDIDO %s COM BLOQUEIO DE ESTOQUE'],
        [StatusPedidoResolver::BLOQUEIO_ESTOQUE, 'PEDIDO %s COM BLOQUEIO DE ESTOQUE'],
        [StatusPedidoResolver::LIBERADO, 'PEDIDO %s LIBERADO PARA MONTAGEM DE CARGA / FATURAMENTO'],
        [StatusPedidoResolver::SEPARACAO, 'ENVIO DO PEDIDO PARA O WMS - ORDEM DE SEPARACAO 876758'],
        [StatusPedidoResolver::BLOQUEIO_CREDITO, 'PEDIDO %s COM BLOQUEIO DE CREDITO'],
        [StatusPedidoResolver::REJEICAO_CREDITO, 'PEDIDO %s COM REJEICAO DE CREDITO - NF 123456, VENCIDA'],
        [StatusPedidoResolver::BLOQUEIO_ARTE, 'PEDIDO %s COM BLOQUEIO DE ARTE'],
        [StatusPedidoResolver::SEPARADO, 'RETORNO DO PEDIDO DO WMS - ORDEM DE SEPARACAO 876854 STATUS COLETADO (TOTALMENTE SEPARADO)'],
        [StatusPedidoResolver::DESCONHECIDO, 'ELIMINACAO DE RESIDUOS - PEDIDO %s ITEM 3'],
    ];

    public function run(): void
    {
        $agora = now();
        $numeroPedido = 800000;
        $pedidosLinhas = [];
        $itensPorPedido = [];

        $codsVendedor = VendedorPerfil::query()
            ->whereHas('user', fn ($q) => $q->role(['vendedor', 'representante']))
            ->pluck('cod_vendedor')
            ->unique();

        $clientesPorVendedor = Cliente::query()->get()->groupBy('cod_vendedor');

        foreach ($codsVendedor as $codVendedor) {
            $clientesDoVendedor = $clientesPorVendedor->get($codVendedor);

            foreach (range(1, fake()->numberBetween(5, 15)) as $i) {
                $cenario = fake()->randomElement(['faturado', 'faturado', 'faturado', 'no_prazo', 'no_prazo', 'atrasado', 'vencendo']);

                $dataPedido = fake()->dateTimeBetween('-60 days', '-1 days');
                $dataFaturamento = null;
                /*
                 * ⚠️ As etapas em aberto, com um MOVIMENTO coerente junto — o seed
                 * precisa parecer com o que o TOTVS manda, senão a tela de dev não
                 * exercita o caminho real. Os pesos imitam a distribuição medida em
                 * produção: quase metade em carga, quase um terço travado em estoque.
                 */
                [$status, $movimento] = fake()->randomElement(self::MOVIMENTOS_EM_ABERTO);

                $dataPrevisao = match ($cenario) {
                    'faturado' => fake()->dateTimeBetween($dataPedido, 'now'),
                    'no_prazo' => fake()->dateTimeBetween('+8 days', '+30 days'),
                    'atrasado' => fake()->dateTimeBetween('-15 days', '-1 days'),
                    'vencendo' => fake()->dateTimeBetween('now', '+7 days'),
                };

                if ($cenario === 'faturado') {
                    $dataFaturamento = fake()->dateTimeBetween($dataPrevisao, 'now');
                    $status = StatusPedidoResolver::FATURADO;
                }

                $numero = (string) $numeroPedido++;
                $clienteId = $clientesDoVendedor?->random()->id ?? null;
                $numItens = fake()->numberBetween(1, 3);
                $valorTotalPedido = 0;

                $itens = [];
                foreach (range(1, $numItens) as $j) {
                    $produto = fake()->randomElement(self::PRODUTOS);
                    $quantidade = fake()->numberBetween(10, 300);
                    $valorUnitario = fake()->randomFloat(2, 5, 250);
                    $valorItem = round($quantidade * $valorUnitario, 2);
                    $valorTotalPedido += $valorItem;

                    $itens[] = [
                        'cod_produto' => $produto['cod'],
                        'descricao' => $produto['desc'],
                        'quantidade' => $quantidade,
                        'quantidade_liberada' => $status === 'faturado' ? $quantidade : fake()->numberBetween(0, $quantidade),
                        'valor_unitario' => $valorUnitario,
                        'valor_total' => $valorItem,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                }

                $pedidosLinhas[] = [
                    'numero_pedido' => $numero,
                    'cliente_id' => $clienteId,
                    'cod_vendedor' => $codVendedor,
                    'data_pedido' => $dataPedido->format('Y-m-d'),
                    'data_previsao_faturamento' => $dataPrevisao->format('Y-m-d'),
                    'data_faturamento' => $dataFaturamento?->format('Y-m-d'),
                    'data_entrega_prevista' => (clone $dataPrevisao)->modify('+'.fake()->numberBetween(1, 10).' days')->format('Y-m-d'),
                    'data_pcp' => $dataPedido->format('Y-m-d'),
                    'carga' => fake()->boolean(60) ? fake()->numerify('CG-#####') : null,
                    'condicao_pagamento' => fake()->randomElement(['28 dias', '30/45/60', 'À vista']),
                    'status' => $status,
                    'historico_totvs' => sprintf($movimento, $numero),
                    'historico_em' => fake()->dateTimeBetween($dataPedido, 'now')->format('Y-m-d H:i:s'),
                    'valor_total' => $valorTotalPedido,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];

                $itensPorPedido[] = ['numero_pedido' => $numero, 'itens' => $itens];
            }
        }

        foreach (array_chunk($pedidosLinhas, 500) as $chunk) {
            DB::table('pedidos')->insert($chunk);
        }

        $idsPorNumero = DB::table('pedidos')->pluck('id', 'numero_pedido');
        $todosItens = [];
        foreach ($itensPorPedido as $grupo) {
            $pedidoId = $idsPorNumero[$grupo['numero_pedido']];
            foreach ($grupo['itens'] as $item) {
                $todosItens[] = ['pedido_id' => $pedidoId] + $item;
            }
        }

        foreach (array_chunk($todosItens, 500) as $chunk) {
            DB::table('pedido_itens')->insert($chunk);
        }
    }
}
