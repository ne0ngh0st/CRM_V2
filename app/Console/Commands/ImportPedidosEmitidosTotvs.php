<?php

namespace App\Console\Commands;

use App\Services\Totvs\ClientesLookup;
use App\Services\Totvs\LeitorRelatorio;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa os pedidos JÁ FATURADOS do relatório 232, direto do arquivo.
 *
 * Par do `totvs:import-pedidos-abertos`: aquele cobre `data_faturamento IS NULL`, este
 * cobre `data_faturamento IS NOT NULL`. Os dois nunca truncam — cada um mexe só na
 * metade que representa.
 *
 * ⚠️ FILTRA `DT_FATURAMENTO` PREENCHIDO, igual ao `legado:import-pedidos` sempre fez. O
 * 232 mudou de formato (23 → 32 colunas) mas manteve o comportamento antigo: 72% das
 * linhas (16.068 de 22.226 na primeira carga) são o MESMO pedido ainda em aberto, já
 * coberto pelo 200. Importar tudo duplicaria quase toda a base — é a mesma decisão que
 * já estava documentada no importador do legado, só verificada de novo contra o formato
 * novo.
 *
 * ⚠️ PROCESSA TODOS OS ARQUIVOS QUE ACHAR, um por mês. Encontrado na prática em 03/09:
 * apareceram DOIS arquivos na pasta ao mesmo tempo — "092026" (setembro, o vigente) e
 * "082026" (agosto, um backfill), e o de agosto foi gravado no disco DEPOIS do de
 * setembro. Um critério de "só o mais recente por mtime" teria escolhido agosto e
 * ignorado setembro em silêncio. Como o merge é por conjunto de datas PRÓPRIO de cada
 * arquivo, processar todos é seguro.
 *
 * Três coisas novas que o formato de 32 colunas trouxe, todas conferidas nos dados reais
 * antes de mapear:
 *
 *   - `COD_CLI`/`LOJA_CLI` direto no relatório. Antes só havia CNPJ para ligar ao
 *     cliente (join menos confiável — CNPJ muda de formatação, cliente pode ter mais de
 *     uma filial com o mesmo CNPJ). Agora liga do mesmo jeito que `pedidos_abertos` já
 *     fazia, via `Normalizador::chaveCliente()`. Medido: 100% dos pedidos faturados
 *     encontram cliente por essa chave.
 *   - `TP_FAT` ('PRODUTO'/'SERVICO') alimenta `pedidos.tipo_faturamento` — só vem
 *     preenchido quando `DT_FATURAMENTO` também está, ou seja, exatamente na fatia que
 *     este comando importa.
 *   - `pedidos.rps`: o relatório NÃO tem uma coluna de número de RPS separada. O que
 *     existe é `SERIE`, que vale "RPS" quando o pedido foi faturado como serviço (e "1"
 *     quando é produto) — e nesse caso é `NOTA_FISCAL` que carrega o NÚMERO do RPS.
 *     Confirmado nos dados: pedido de serviço tem no máximo 2 itens (média 1,08) e
 *     nenhum caso observado com mais de um número de documento por pedido. `rps` é
 *     preenchido só quando `tipo_faturamento = servico`, com o `NOTA_FISCAL` do item.
 *
 * ⚠️ `DATA_PCP` vem como "01/01/1900" em 100% das linhas — sentinela de "sem data" do
 * TOTVS, não uma data real. Tratado em `Normalizador::data()`, não aqui: é convenção do
 * TOTVS, não deste relatório especificamente.
 *
 * ⚠️ `DTA_LIBERADA` é um nome enganoso — apesar do "DTA" (data), o conteúdo é a
 * QUANTIDADE liberada ("3.224,82"), não uma data. Mapeado como número, não como data.
 */
class ImportPedidosEmitidosTotvs extends Command
{
    protected $signature = 'totvs:import-pedidos-emitidos
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa os pedidos faturados do relatório 232 do TOTVS, direto do arquivo';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $arquivos = Relatorios::todos('pedidos_emitidos');

        if ($arquivos === []) {
            $this->error('Nenhum relatório 232 encontrado.');

            return self::FAILURE;
        }

        $clientePorChave = ClientesLookup::porChave();

        $totalPedidos = 0;
        $totalItens = 0;

        DB::transaction(function () use ($arquivos, $clientePorChave, $dryRun, &$totalPedidos, &$totalItens) {
            foreach ($arquivos as $caminho) {
                [$pedidos, $itens] = $this->processarArquivo($caminho, $clientePorChave, $dryRun);
                $totalPedidos += $pedidos;
                $totalItens += $itens;
            }
        });

        $prefixo = $dryRun ? '[dry-run] ' : '';
        $this->info($prefixo.'Pedidos faturados gravados no total: '.number_format($totalPedidos, 0, ',', '.'));
        $this->line('Itens no total: '.number_format($totalItens, 0, ',', '.'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $clientePorChave
     * @return array{0: int, 1: int} pedidos, itens
     */
    private function processarArquivo(string $caminho, array $clientePorChave, bool $dryRun): array
    {
        $this->newLine();
        $this->line('── '.basename($caminho));

        try {
            $leitor = Relatorios::abrirArquivo($caminho, 'pedidos_emitidos');
            $leitor->exigirColunas([
                'PEDIDO', 'DT_EMISSAO', 'PREV_FAT', 'PREV_ENTR', 'DATA_PCP', 'CARGA',
                'CONDPAGTO', 'COD_CLI', 'LOJA_CLI', 'COD_VENDEDOR', 'COD_PROD', 'DESC_PROD',
                'PESO_LIQ', 'PRC_VENDA', 'QTDA_VENDA', 'DTA_LIBERADA', 'VLR_TOTAL',
                'DT_FATURAMENTO', 'NOTA_FISCAL', 'SERIE', 'TP_FAT',
            ]);
        } catch (RuntimeException $e) {
            // Um arquivo com formato antigo esquecido na pasta (ex.: o extinto "META
            // VENDA - SQL.csv") não pode derrubar o import dos outros meses válidos.
            // Pula com aviso — quem decide se apaga o arquivo velho é o Tony.
            $this->warn('  '.$e->getMessage());
            $this->warn('  pulando este arquivo.');

            return [0, 0];
        }

        [$cabecalhos, $itens, $linhas, $foraDoFiltro, $semCliente] = $this->lerArquivo($leitor, $clientePorChave);

        $this->line(sprintf(
            '  %s linhas, %s faturadas (%s fora do filtro — ainda em aberto), %s pedidos.',
            number_format($linhas, 0, ',', '.'),
            number_format($linhas - $foraDoFiltro, 0, ',', '.'),
            number_format($foraDoFiltro, 0, ',', '.'),
            number_format(count($cabecalhos), 0, ',', '.')
        ));

        if ($semCliente > 0) {
            $this->warn("  pedidos sem cliente correspondente no CRM: {$semCliente}");
        }

        if ($cabecalhos === []) {
            return [0, 0];
        }

        $existentes = DB::table('pedidos')->whereIn('numero_pedido', array_keys($cabecalhos))->count();
        $this->line('  já existiam (serão atualizados): '.number_format($existentes, 0, ',', '.'));

        $totalItens = array_sum(array_map('count', $itens));

        if ($dryRun) {
            $this->line('  [dry-run] gravaria '.number_format(count($cabecalhos), 0, ',', '.').' pedidos.');

            return [count($cabecalhos), $totalItens];
        }

        $this->gravar($cabecalhos, $itens);

        return [count($cabecalhos), $totalItens];
    }

    /**
     * @param  array<string, int>  $clientePorChave
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, list<array<string, mixed>>>, 2: int, 3: int, 4: int}
     */
    private function lerArquivo(LeitorRelatorio $leitor, array $clientePorChave): array
    {
        $cabecalhos = [];
        $itens = [];
        $linhas = 0;
        $foraDoFiltro = 0;
        $semCliente = 0;
        $clienteJaContado = [];

        foreach ($leitor->linhas() as $linha) {
            $linhas++;
            $numero = $linha['PEDIDO'];

            // Mesmo filtro do legado: só a fatia faturada. O resto é o mesmo pedido
            // ainda em aberto, já coberto por PEDIDOS_EM_ABERTO.
            if ($numero === '' || $linha['DT_FATURAMENTO'] === '') {
                $foraDoFiltro++;

                continue;
            }

            $tipoFaturamento = strtolower($linha['TP_FAT']) === 'servico' ? 'servico' : 'produto';

            if (! isset($cabecalhos[$numero])) {
                $chave = Normalizador::chaveCliente($linha['COD_CLI'], $linha['LOJA_CLI']);
                $clienteId = $clientePorChave[$chave] ?? null;

                if ($clienteId === null && ! isset($clienteJaContado[$numero])) {
                    $semCliente++;
                    $clienteJaContado[$numero] = true;
                }

                $cabecalhos[$numero] = [
                    'cliente_id' => $clienteId,
                    'cod_vendedor' => $linha['COD_VENDEDOR'],
                    'data_pedido' => Normalizador::data($linha['DT_EMISSAO']),
                    'data_previsao_faturamento' => Normalizador::data($linha['PREV_FAT']),
                    'data_faturamento' => Normalizador::data($linha['DT_FATURAMENTO']),
                    'data_entrega_prevista' => Normalizador::data($linha['PREV_ENTR']),
                    'data_pcp' => Normalizador::data($linha['DATA_PCP']),
                    'carga' => Normalizador::valorOuNull($linha['CARGA']),
                    'condicao_pagamento' => Normalizador::valorOuNull($linha['CONDPAGTO']),
                    'status' => 'faturado',
                    'tipo_faturamento' => $tipoFaturamento,
                    // Só preenchido para serviço, com o número que NOTA_FISCAL carrega
                    // quando SERIE=RPS — ver o cabeçalho da classe.
                    'rps' => $tipoFaturamento === 'servico' ? Normalizador::valorOuNull($linha['NOTA_FISCAL']) : null,
                    'valor_total' => 0,
                ];
            }

            $valor = Normalizador::numero($linha['VLR_TOTAL']);
            $cabecalhos[$numero]['valor_total'] += $valor;

            $itens[$numero][] = [
                'cod_produto' => Normalizador::valorOuNull($linha['COD_PROD']),
                'descricao' => $linha['DESC_PROD'],
                'nota_fiscal' => Normalizador::valorOuNull($linha['NOTA_FISCAL']),
                'quantidade' => Normalizador::numero($linha['QTDA_VENDA']),
                'quantidade_liberada' => Normalizador::numero($linha['DTA_LIBERADA']),
                'peso_liquido' => Normalizador::pesoOuNull($linha['PESO_LIQ']),
                'valor_unitario' => Normalizador::numero($linha['PRC_VENDA']),
                'valor_total' => $valor,
            ];
        }

        // Pedido sem data de emissão não entra: mesma regra dos outros dois importadores.
        foreach ($cabecalhos as $numero => $cab) {
            if ($cab['data_pedido'] === null) {
                unset($cabecalhos[$numero], $itens[$numero]);
            }
        }

        return [$cabecalhos, $itens, $linhas, $foraDoFiltro, $semCliente];
    }

    /**
     * @param  array<string, array<string, mixed>>  $cabecalhos
     * @param  array<string, list<array<string, mixed>>>  $itens
     */
    private function gravar(array $cabecalhos, array $itens): void
    {
        $agora = now();
        $numeros = array_keys($cabecalhos);

        $lote = [];
        foreach ($cabecalhos as $numero => $cab) {
            $lote[] = $cab + ['numero_pedido' => $numero, 'created_at' => $agora, 'updated_at' => $agora];
        }

        foreach (array_chunk($lote, 500) as $pedaco) {
            DB::table('pedidos')->upsert($pedaco, ['numero_pedido'], [
                'cliente_id', 'cod_vendedor', 'data_pedido', 'data_previsao_faturamento',
                'data_faturamento', 'data_entrega_prevista', 'data_pcp', 'carga',
                'condicao_pagamento', 'status', 'tipo_faturamento', 'rps', 'valor_total', 'updated_at',
            ]);
        }

        $idPorNumero = DB::table('pedidos')->whereIn('numero_pedido', $numeros)->pluck('id', 'numero_pedido');

        // Troca os itens em vez de acrescentar: o relatório é o retrato completo do
        // pedido faturado naquele momento, mesma lógica do pedidos_abertos.
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
