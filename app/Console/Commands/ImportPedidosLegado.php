<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportPedidosLegado extends Command
{
    protected $signature = 'legado:import-pedidos {--fonte=homolog : homolog ou producao}';

    protected $description = 'Import de pedidos (PEDIDOS_EM_ABERTO = aberto + META_VENDA faturado) do TOTVS pro CRM-V2';

    private array $clientesPorCodLoja = [];

    private array $clientesPorCnpj = [];

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $pdo = LegadoConexao::pdo($fonte);

        $this->carregarClientes();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('pedido_itens')->truncate();
        DB::table('pedidos')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info('pedidos/pedido_itens zerados — recarga completa.');

        $totalAberto = $this->importarAbertos($pdo);
        $this->info("Pedidos em aberto: {$totalAberto}");

        $totalFaturado = $this->importarFaturados($pdo);
        $this->info("Pedidos faturados: {$totalFaturado}");

        return self::SUCCESS;
    }

    private function carregarClientes(): void
    {
        DB::table('clientes')->select('id', 'cod_cliente', 'loja', 'cnpj')
            ->orderBy('id')
            ->cursor()
            ->each(function ($cliente) {
                $this->clientesPorCodLoja[$cliente->cod_cliente.'|'.$cliente->loja] = $cliente->id;
                if ($cliente->cnpj !== null) {
                    $this->clientesPorCnpj[$cliente->cnpj] = $cliente->id;
                }
            });
    }

    /**
     * PEDIDOS_EM_ABERTO: grão de item, tem COD_CLIENT+LOJA direto. Nenhum pedido aqui tem
     * status estruturado de verdade (ver migration 2026_07_29_090549) — todos recebem
     * 'pendente_totvs' até o relatório de origem expor um código real.
     */
    private function importarAbertos(PDO $pdo): int
    {
        $stmt = $pdo->query(
            'SELECT COD_CLIENT, LOJA, COD_VENDEDOR, NUMERO_PEDIDO, DATA_PEDIDO, DATA_PREVISAO_FATURAMENTO, '
            .'DATA_ENTREGA, DATA_PCP, CARGA, CONDICAO_PAGAMENTO, COD_PRODUTO, DESCRICAO_PRODUTO, '
            .'QUANTIDADE_VENDA, QUANTIDADE_LIBERADA, VLR_TOTAL '
            .'FROM PEDIDOS_EM_ABERTO ORDER BY NUMERO_PEDIDO'
        );

        return $this->processarGrupos(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn ($row) => trim((string) $row['NUMERO_PEDIDO']),
            function (array $linhas) {
                $primeira = $linhas[0];

                return [
                    'cliente_id' => $this->clientesPorCodLoja[trim((string) $primeira['COD_CLIENT']).'|'.trim((string) $primeira['LOJA'])] ?? null,
                    'cod_vendedor' => trim((string) $primeira['COD_VENDEDOR']),
                    'data_pedido' => self::dataOuNull($primeira['DATA_PEDIDO']),
                    'data_previsao_faturamento' => self::dataOuNull($primeira['DATA_PREVISAO_FATURAMENTO']),
                    'data_faturamento' => null,
                    'data_entrega_prevista' => self::dataOuNull($primeira['DATA_ENTREGA']),
                    'data_pcp' => self::dataOuNull($primeira['DATA_PCP']),
                    'carga' => self::valorOuNull($primeira['CARGA']),
                    'condicao_pagamento' => self::valorOuNull($primeira['CONDICAO_PAGAMENTO']),
                    'status' => 'pendente_totvs',
                ];
            },
            function (array $linhas) {
                return array_map(fn ($row) => [
                    'cod_produto' => self::valorOuNull($row['COD_PRODUTO']),
                    'descricao' => trim((string) $row['DESCRICAO_PRODUTO']),
                    'quantidade' => $row['QUANTIDADE_VENDA'] ?? 0,
                    'quantidade_liberada' => $row['QUANTIDADE_LIBERADA'],
                    'valor_unitario' => self::valorUnitario($row['VLR_TOTAL'], $row['QUANTIDADE_VENDA']),
                    'valor_total' => $row['VLR_TOTAL'] ?? 0,
                ], $linhas);
            }
        );
    }

    /**
     * META_VENDA: grão de item, só tem CNPJ (não cod_cliente/loja) — liga em clientes por
     * CNPJ completo. Só a fatia com DT_FATURAMENTO preenchido interessa aqui: o resto da
     * tabela (~92% das linhas) é o mesmo pedido ainda em aberto, já coberto por
     * PEDIDOS_EM_ABERTO — importar tudo duplicaria quase toda a base.
     */
    private function importarFaturados(PDO $pdo): int
    {
        $stmt = $pdo->query(
            'SELECT CNPJ, COD_VENDEDOR, PEDIDO, DT_EMISSAO, PREV_FAT, DT_FATURAMENTO, COD_PROD, DESC_PROD, '
            .'QTDA_VENDA, PRC_VENDA, VLR_TOTAL '
            ."FROM META_VENDA WHERE DT_FATURAMENTO IS NOT NULL AND TRIM(DT_FATURAMENTO) <> '' "
            .'ORDER BY PEDIDO'
        );

        return $this->processarGrupos(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn ($row) => trim((string) $row['PEDIDO']),
            function (array $linhas) {
                $primeira = $linhas[0];

                return [
                    'cliente_id' => $this->clientesPorCnpj[trim((string) $primeira['CNPJ'])] ?? null,
                    'cod_vendedor' => trim((string) $primeira['COD_VENDEDOR']),
                    'data_pedido' => self::dataOuNull($primeira['DT_EMISSAO']),
                    'data_previsao_faturamento' => self::dataOuNull($primeira['PREV_FAT']),
                    'data_faturamento' => self::dataOuNull($primeira['DT_FATURAMENTO']),
                    'data_entrega_prevista' => null,
                    'data_pcp' => null,
                    'carga' => null,
                    'condicao_pagamento' => null,
                    'status' => 'faturado',
                ];
            },
            function (array $linhas) {
                return array_map(fn ($row) => [
                    'cod_produto' => self::valorOuNull($row['COD_PROD']),
                    'descricao' => trim((string) $row['DESC_PROD']),
                    'quantidade' => $row['QTDA_VENDA'] ?? 0,
                    'quantidade_liberada' => $row['QTDA_VENDA'] ?? 0,
                    'valor_unitario' => $row['PRC_VENDA'],
                    'valor_total' => $row['VLR_TOTAL'] ?? 0,
                ], $linhas);
            }
        );
    }

    /**
     * Agrupa linhas (já ordenadas pela chave de pedido) em cabeçalho + itens, insere em
     * lote e devolve quantos pedidos (cabeçalhos) foram criados.
     */
    private function processarGrupos(array $linhas, \Closure $chave, \Closure $montaCabecalho, \Closure $montaItens): int
    {
        $agora = now();
        $lotesCabecalho = [];
        $itensPorNumero = [];
        $grupoAtual = null;
        $bufferGrupo = [];

        $flush = function () use (&$bufferGrupo, &$grupoAtual, &$lotesCabecalho, &$itensPorNumero, $montaCabecalho, $montaItens, $agora) {
            if ($grupoAtual === null || $bufferGrupo === []) {
                return;
            }

            $cabecalho = $montaCabecalho($bufferGrupo);
            $itens = $montaItens($bufferGrupo);
            $valorTotal = array_sum(array_column($itens, 'valor_total'));

            if ($cabecalho['data_pedido'] === null) {
                return;
            }

            $lotesCabecalho[] = array_merge($cabecalho, [
                'numero_pedido' => $grupoAtual,
                'valor_total' => $valorTotal,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
            $itensPorNumero[$grupoAtual] = $itens;
        };

        foreach ($linhas as $row) {
            $numero = $chave($row);
            if ($numero === '') {
                continue;
            }

            if ($grupoAtual !== null && $numero !== $grupoAtual) {
                $flush();
                $bufferGrupo = [];
            }

            $grupoAtual = $numero;
            $bufferGrupo[] = $row;
        }
        $flush();

        // As linhas cruas já foram todas transformadas em cabeçalho + itens; segurar
        // o resultado do fetchAll() daqui pra frente é carregar 161 mil arrays à toa.
        unset($linhas);

        $total = 0;
        foreach (array_chunk($lotesCabecalho, 500, true) as $lote) {
            DB::table('pedidos')->upsert(
                $lote,
                ['numero_pedido'],
                ['cliente_id', 'cod_vendedor', 'data_pedido', 'data_previsao_faturamento', 'data_faturamento',
                    'data_entrega_prevista', 'data_pcp', 'carga', 'condicao_pagamento', 'status', 'valor_total', 'updated_at']
            );
            $total += count($lote);
        }

        $idsPorNumero = DB::table('pedidos')->whereIn('numero_pedido', array_keys($itensPorNumero))->pluck('id', 'numero_pedido');

        // Um pedido pode existir nas duas rodadas (aberto nesta carga, já faturado na outra
        // fonte) — o cabeçalho já foi upsertado acima, mas os itens da rodada anterior pra
        // esses mesmos pedidos ainda estão na tabela. Limpa antes de inserir os desta rodada,
        // senão o pedido fica com itens duplicados/misturados das duas fontes.
        DB::table('pedido_itens')->whereIn('pedido_id', $idsPorNumero->values())->delete();

        /*
         * ⚠️ Insere enquanto percorre, em vez de montar a lista inteira e depois
         * fatiar com array_chunk(). Aquela versão estourava o `memory_limit` de 512M
         * do CLI com "Allowed memory size exhausted" na rodada de FATURADOS: os
         * 161.114 itens de META_VENDA ficavam em memória TRÊS vezes ao mesmo tempo
         * (fetchAll + `$itensPorNumero` + a lista final). A rodada de abertos, com
         * 25 mil itens, sempre coube — por isso o sintoma era só pedido faturado
         * ficar sem item, e não um erro óbvio de import quebrado.
         *
         * Regra de ouro nº 6 em ação: o import "funcionava" com 9.154 faturados e
         * passou a morrer com 12.006. Se um dia voltar a apertar, o próximo passo é
         * parar de usar fetchAll() e percorrer o cursor do PDO.
         *
         * Percorre `array_keys` (e não o array direto) de propósito: dar `unset` na
         * própria coleção sendo iterada por valor faz o PHP separar/copiar o array,
         * o que dobraria o consumo justamente no ponto que se quer aliviar.
         */
        $buffer = [];
        foreach (array_keys($itensPorNumero) as $numero) {
            $pedidoId = $idsPorNumero[$numero] ?? null;

            if ($pedidoId !== null) {
                foreach ($itensPorNumero[$numero] as $item) {
                    $buffer[] = $item + [
                        'pedido_id' => $pedidoId,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];

                    if (count($buffer) >= 1000) {
                        DB::table('pedido_itens')->insert($buffer);
                        $buffer = [];
                    }
                }
            }

            // Grupo já gravado (ou descartado): libera antes de seguir.
            unset($itensPorNumero[$numero]);
        }

        if ($buffer !== []) {
            DB::table('pedido_itens')->insert($buffer);
        }

        return $total;
    }

    private static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    private static function dataOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));
        if ($valor === '') {
            return null;
        }

        $data = DateTime::createFromFormat('d/m/Y', substr($valor, 0, 10));

        return $data ? $data->format('Y-m-d') : null;
    }

    private static function valorUnitario(mixed $valorTotal, mixed $quantidade): float
    {
        $quantidade = (float) $quantidade;
        if ($quantidade <= 0) {
            return 0.0;
        }

        return round(((float) $valorTotal) / $quantidade, 2);
    }
}
