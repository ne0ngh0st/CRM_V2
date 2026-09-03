<?php

namespace App\Console\Commands;

use App\Services\Totvs\ClientesLookup;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa os pedidos em aberto do relatório 200, direto do arquivo.
 *
 * ⚠️ NÃO TRUNCA A TABELA. `pedidos` guarda as duas coisas — aberto e faturado —, e o 200
 * é o retrato de "o que está em aberto AGORA". O comando equivalente do legado
 * (`legado:import-pedidos`) trunca tudo porque importa as duas fontes de uma vez; aqui,
 * truncar apagaria os 12 mil pedidos faturados que vêm do 232.
 *
 * O que "em aberto" quer dizer no banco: `data_faturamento IS NULL`.
 *
 * ⚠️ UM PEDIDO PODE APARECER NAS DUAS FONTES, e aí o 200 ganha. Aconteceu com 13 pedidos
 * na primeira execução: estavam marcados como faturados (232 de 31/08) e apareceram no
 * relatório de abertos do dia. A causa provável é faturamento parcial — parte saiu, parte
 * continua pendente — e o v2 só tem UMA linha por `numero_pedido`, então não dá para
 * representar os dois estados.
 *
 * A escolha é deliberada: o 200 é a fonte mais nova e é o que o vendedor precisa ver como
 * pendente. O custo é que a nota fiscal e o peso daquele pedido somem até o próximo 232
 * trazê-los de volta. O comando CONTA e AVISA quantos foram convertidos — a primeira
 * versão fazia a mesma coisa em silêncio, que é o que não podia.
 *
 * Três coisas acontecem, nesta ordem, dentro de uma transação:
 *
 *   1. upsert dos pedidos do relatório (por `numero_pedido`, que é unique)
 *   2. troca dos itens desses pedidos
 *   3. remoção dos que estavam abertos e sumiram do relatório — foram faturados ou
 *      cancelados no TOTVS. Sem este passo, pedido faturado ficaria eternamente na
 *      tela de "em aberto", que é o defeito mais visível que este import poderia ter.
 *
 * ⚠️ O relatório traz `HISTORICO` preenchido em 100% das linhas, mas é TEXTO LIVRE, sem
 * código estruturado — por isso todo pedido em aberto continua entrando como
 * `pendente_totvs`, e não com um status de verdade. É o ajuste que está pendente com o
 * Adriano (ver docs/importacao-dados-legado.md §8.3). Não adivinhar status a partir da
 * frase: "COM BLOQUEIO DE ESTOQUE" hoje pode virar outra redação amanhã.
 */
class ImportPedidosAbertosTotvs extends Command
{
    protected $signature = 'totvs:import-pedidos-abertos
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa os pedidos em aberto do relatório 200 do TOTVS, direto do arquivo';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leitor = Relatorios::abrir('pedidos_abertos');
        $leitor->exigirColunas([
            'COD_CLI', 'LOJA_CLI', 'COD_REPRES', 'N_PEDIDO', 'DATA_PED', 'DT_PREVFAT',
            'DT_ENTREGA', 'DT_PCP', 'CARGA', 'COND_PAGTO', 'COD_PROD', 'DESC_PROD',
            'QTD_VENDA', 'QTD_LIBER', 'VLR_PEDIDO',
        ]);

        $clientePorChave = ClientesLookup::porChave();

        $cabecalhos = [];
        $itens = [];
        $linhas = 0;
        $semCliente = 0;

        foreach ($leitor->linhas() as $linha) {
            $numero = $linha['N_PEDIDO'];
            if ($numero === '') {
                continue;
            }

            $linhas++;

            if (! isset($cabecalhos[$numero])) {
                $chave = Normalizador::chaveCliente($linha['COD_CLI'], $linha['LOJA_CLI']);
                $clienteId = $clientePorChave[$chave] ?? null;

                if ($clienteId === null) {
                    $semCliente++;
                }

                $cabecalhos[$numero] = [
                    'cliente_id' => $clienteId,
                    'cod_vendedor' => $linha['COD_REPRES'],
                    'data_pedido' => Normalizador::data($linha['DATA_PED']),
                    'data_previsao_faturamento' => Normalizador::data($linha['DT_PREVFAT']),
                    'data_faturamento' => null,
                    'data_entrega_prevista' => Normalizador::data($linha['DT_ENTREGA']),
                    'data_pcp' => Normalizador::data($linha['DT_PCP']),
                    'carga' => Normalizador::valorOuNull($linha['CARGA']),
                    'condicao_pagamento' => Normalizador::valorOuNull($linha['COND_PAGTO']),
                    'status' => 'pendente_totvs',
                    'valor_total' => 0,
                ];
            }

            $valor = Normalizador::numero($linha['VLR_PEDIDO']);
            $quantidade = Normalizador::numero($linha['QTD_VENDA']);

            $cabecalhos[$numero]['valor_total'] += $valor;

            $itens[$numero][] = [
                'cod_produto' => Normalizador::valorOuNull($linha['COD_PROD']),
                'descricao' => $linha['DESC_PROD'],
                'nota_fiscal' => null,
                'quantidade' => $quantidade,
                'quantidade_liberada' => Normalizador::numero($linha['QTD_LIBER']),
                'peso_liquido' => null,
                'valor_unitario' => $quantidade > 0 ? round($valor / $quantidade, 2) : 0,
                'valor_total' => $valor,
            ];
        }

        // Pedido sem data não entra: `data_pedido` é NOT NULL e é por ela que a tela
        // ordena. Mesma regra do import do legado.
        $semData = 0;
        foreach ($cabecalhos as $numero => $cab) {
            if ($cab['data_pedido'] === null) {
                unset($cabecalhos[$numero], $itens[$numero]);
                $semData++;
            }
        }

        $this->line(sprintf(
            '200 - Pedidos em aberto: %s linhas, %s pedidos, %s itens.',
            number_format($linhas, 0, ',', '.'),
            number_format(count($cabecalhos), 0, ',', '.'),
            number_format(array_sum(array_map('count', $itens)), 0, ',', '.')
        ));

        $obsoletos = DB::table('pedidos')
            ->whereNull('data_faturamento')
            ->whereNotIn('numero_pedido', array_keys($cabecalhos))
            ->count();

        // Estavam faturados e voltaram a aparecer como abertos — ver o aviso no
        // cabeçalho da classe. Contado ANTES da escrita, senão já não dá para saber.
        $reabertos = DB::table('pedidos')
            ->whereNotNull('data_faturamento')
            ->whereIn('numero_pedido', array_keys($cabecalhos))
            ->count();

        if ($dryRun) {
            $this->info('[dry-run] Gravaria '.number_format(count($cabecalhos), 0, ',', '.').' pedidos em aberto.');
            $this->line('[dry-run] Removeria '.number_format($obsoletos, 0, ',', '.').' que saíram do relatório (faturados ou cancelados).');
        } else {
            DB::transaction(function () use ($cabecalhos, $itens) {
                $this->gravar($cabecalhos, $itens);
            });

            $this->info('Pedidos em aberto gravados: '.number_format(count($cabecalhos), 0, ',', '.'));
            $this->line('Removidos (saíram do relatório): '.number_format($obsoletos, 0, ',', '.'));
        }

        if ($reabertos > 0) {
            $this->warn('Estavam FATURADOS e voltaram a aberto: '.number_format($reabertos, 0, ',', '.'));
            $this->line('  → o 200 é a fonte mais nova e prevalece (provável faturamento parcial).');
            $this->line('  → a nota fiscal desses pedidos volta no próximo import do 232.');
        }

        if ($semCliente > 0) {
            $this->warn('Pedidos sem cliente correspondente no CRM: '.number_format($semCliente, 0, ',', '.'));
        }

        if ($semData > 0) {
            $this->warn("Ignorados (sem data de pedido): {$semData}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cabecalhos
     * @param  array<string, list<array<string, mixed>>>  $itens
     */
    private function gravar(array $cabecalhos, array $itens): void
    {
        $agora = now();
        $numeros = array_keys($cabecalhos);

        // Estava aberto e sumiu do relatório: foi faturado ou cancelado no TOTVS.
        // Os itens vão junto pelo ON DELETE CASCADE de pedido_itens.
        DB::table('pedidos')
            ->whereNull('data_faturamento')
            ->whereNotIn('numero_pedido', $numeros)
            ->delete();

        $lote = [];
        foreach ($cabecalhos as $numero => $cab) {
            $lote[] = $cab + ['numero_pedido' => $numero, 'created_at' => $agora, 'updated_at' => $agora];
        }

        foreach (array_chunk($lote, 500) as $pedaco) {
            DB::table('pedidos')->upsert($pedaco, ['numero_pedido'], [
                'cliente_id', 'cod_vendedor', 'data_pedido', 'data_previsao_faturamento',
                'data_faturamento', 'data_entrega_prevista', 'data_pcp', 'carga',
                'condicao_pagamento', 'status', 'valor_total', 'updated_at',
            ]);
        }

        $idPorNumero = DB::table('pedidos')->whereIn('numero_pedido', $numeros)->pluck('id', 'numero_pedido');

        // Troca os itens em vez de acrescentar: o relatório é o retrato completo do
        // pedido, e quantidade liberada muda de um dia para o outro.
        DB::table('pedido_itens')->whereIn('pedido_id', $idPorNumero->values())->delete();

        $buffer = [];
        foreach ($itens as $numero => $linhas) {
            $pedidoId = $idPorNumero[$numero] ?? null;
            if ($pedidoId === null) {
                continue;
            }

            foreach ($linhas as $item) {
                $buffer[] = $item + ['pedido_id' => $pedidoId, 'created_at' => $agora, 'updated_at' => $agora];

                if (count($buffer) >= 1000) {
                    DB::table('pedido_itens')->insert($buffer);
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            DB::table('pedido_itens')->insert($buffer);
        }
    }

}
