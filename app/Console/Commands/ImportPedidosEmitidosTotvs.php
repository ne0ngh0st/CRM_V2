<?php

namespace App\Console\Commands;

use App\Services\Pedidos\StatusPedidoResolver;
use App\Services\Totvs\ClientesLookup;
use App\Services\Totvs\LeitorRelatorio;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa os pedidos emitidos do relatório 232 — ABERTOS E FATURADOS —, direto do arquivo.
 *
 * 🥇 O 232 É A FONTE DA VENDA (decisão do Tony, 2026-09-25). Até esta data este comando
 * gravava só as linhas faturadas e os pedidos em aberto vinham do relatório 200. Os dois
 * relatórios não contam a mesma coisa: o 232 filtra pedido que GERA FINANCEIRO
 * (`GERAFINANCEIRO` vem "S" em 100% das linhas) e o 200 não — traz também remessa de
 * almoxarifado virtual e transferência entre filiais. Setembro/2026 saiu R$ 61,5 mi no
 * CRM e no BI contra R$ 58,4 mi no Excel do 232: +R$ 12,7 mi de remessa do 200 e
 * −R$ 9,6 mi de pedido em aberto que o 200 (três dias mais velho) ainda não tinha.
 *
 * Agora: todas as linhas entram, o pedido só fica FATURADO quando todos os itens
 * faturaram (faturamento parcial continua em aberto, com o valor cheio — é o que o
 * relatório soma), e o 200 passou a só atualizar o STATUS de pedido que este comando
 * trouxe ({@see ImportPedidosAbertosTotvs}). Resultado: o total do mês bate com o
 * Excel do 232 por construção.
 *
 * ⚠️ RECORTE POR ARQUIVO: pedido com `data_pedido` dentro da faixa de datas de um
 * arquivo e que NÃO está nele é apagado. É o que tira as remessas que o 200 gravou
 * antes desta mudança, e o que apaga pedido cancelado no TOTVS.
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

    protected $description = 'Importa os pedidos emitidos (abertos e faturados) do relatório 232 do TOTVS';

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
        $this->info($prefixo.'Pedidos emitidos gravados no total: '.number_format($totalPedidos, 0, ',', '.'));
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
                'FILIAL', 'PEDIDO', 'DT_EMISSAO', 'PREV_FAT', 'PREV_ENTR', 'DATA_PCP', 'CARGA',
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
            '  %s linhas, %s faturadas, %s em aberto, %s pedidos.',
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

        // ⚠️ Nunca `array_keys()` cru contra `numero_pedido` — ver Normalizador::numerosDePedido().
        $existentes = DB::table('pedidos')
            ->whereIn('numero_pedido', Normalizador::numerosDePedido($cabecalhos))
            ->count();
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

            if ($numero === '') {
                continue;
            }

            $dataFaturamento = Normalizador::data($linha['DT_FATURAMENTO']);
            if ($dataFaturamento === null) {
                $foraDoFiltro++; // item ainda em aberto — entra, e conta para o resumo
            }

            // `TP_FAT` só vem preenchido na linha faturada.
            $tipoFaturamento = match (strtolower($linha['TP_FAT'])) {
                'servico' => 'servico',
                'produto' => 'produto',
                default => null,
            };

            if (! isset($cabecalhos[$numero])) {
                $chave = Normalizador::chaveCliente($linha['COD_CLI'], $linha['LOJA_CLI']);
                $clienteId = $clientePorChave[$chave] ?? null;

                if ($clienteId === null && ! isset($clienteJaContado[$numero])) {
                    $semCliente++;
                    $clienteJaContado[$numero] = true;
                }

                $cabecalhos[$numero] = [
                    'cliente_id' => $clienteId,
                    'filial' => Normalizador::filial($linha['FILIAL']),
                    'cod_vendedor' => Normalizador::codigoVendedor($linha['COD_VENDEDOR']) ?? '',
                    'data_pedido' => Normalizador::data($linha['DT_EMISSAO']),
                    'data_previsao_faturamento' => Normalizador::data($linha['PREV_FAT']),
                    'data_faturamento' => $dataFaturamento,
                    'tem_item_aberto' => false,
                    'data_entrega_prevista' => Normalizador::data($linha['PREV_ENTR']),
                    'data_pcp' => Normalizador::data($linha['DATA_PCP']),
                    'carga' => Normalizador::valorOuNull($linha['CARGA']),
                    'condicao_pagamento' => Normalizador::valorOuNull($linha['CONDPAGTO']),
                    /*
                     * ⚠️ Este import NÃO mexe em `historico_totvs`/`historico_em` — as
                     * colunas ficam de fora do upsert de propósito. Um pedido que passou
                     * pelo relatório 200 antes de faturar guarda ali o último movimento
                     * em aberto (um bloqueio, uma carga), com a data em que aconteceu. É
                     * histórico legítimo e a tela sempre o mostra datado; zerar aqui
                     * apagaria o único registro que o CRM tem de por onde o pedido passou.
                     */
                    'status' => StatusPedidoResolver::FATURADO,
                    'tipo_faturamento' => null,
                    'rps' => null,
                    'valor_total' => 0,
                ];
            }

            // Pedido parcialmente faturado continua EM ABERTO: basta um item sem nota.
            if ($dataFaturamento === null) {
                $cabecalhos[$numero]['tem_item_aberto'] = true;
            } elseif ($cabecalhos[$numero]['data_faturamento'] === null || $dataFaturamento > $cabecalhos[$numero]['data_faturamento']) {
                $cabecalhos[$numero]['data_faturamento'] = $dataFaturamento;
            }

            if ($tipoFaturamento !== null && $cabecalhos[$numero]['tipo_faturamento'] === null) {
                $cabecalhos[$numero]['tipo_faturamento'] = $tipoFaturamento;
                // Só preenchido para serviço, com o número que NOTA_FISCAL carrega
                // quando SERIE=RPS — ver o cabeçalho da classe.
                $cabecalhos[$numero]['rps'] = $tipoFaturamento === 'servico' ? Normalizador::valorOuNull($linha['NOTA_FISCAL']) : null;
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

                continue;
            }

            if ($cab['tem_item_aberto']) {
                // Em aberto (inteiro ou parcial): a etapa vem do 200, que roda depois.
                $cabecalhos[$numero]['data_faturamento'] = null;
                $cabecalhos[$numero]['status'] = StatusPedidoResolver::DESCONHECIDO;
            }

            unset($cabecalhos[$numero]['tem_item_aberto']);
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

        // ⚠️ Nunca `array_keys()` cru contra `numero_pedido` — ver Normalizador::numerosDePedido().
        // Aqui a lista mista não estoura (só há SELECT), ela responde ERRADO: todo
        // alfanumérico da coluna vira 0 na comparação numérica e casa por engano, e é
        // esse resultado que decide quais itens de pedido são apagados logo abaixo.
        $numeros = Normalizador::numerosDePedido($cabecalhos);

        // Recorte: o arquivo é o retrato completo da faixa de datas que cobre. Pedido da
        // faixa que não está nele saiu do 232 — cancelado, ou remessa sem financeiro que o
        // 200 gravou antes de 2026-09-25. Os itens vão junto pelo ON DELETE CASCADE.
        $datas = array_column($cabecalhos, 'data_pedido');
        $removidos = DB::table('pedidos')
            ->whereBetween('data_pedido', [min($datas), max($datas)])
            ->whereNotIn('numero_pedido', $numeros)
            ->delete();
        $this->line('  removidos (na faixa '.min($datas).' a '.max($datas).' e fora do arquivo): '.number_format($removidos, 0, ',', '.'));

        $faturados = [];
        $abertos = [];
        foreach ($cabecalhos as $numero => $cab) {
            $linha = $cab + ['numero_pedido' => $numero, 'created_at' => $agora, 'updated_at' => $agora];
            if ($cab['data_faturamento'] === null) {
                $abertos[] = $linha;
            } else {
                $faturados[] = $linha;
            }
        }

        $colunas = [
            'cliente_id', 'filial', 'cod_vendedor', 'data_pedido', 'data_previsao_faturamento',
            'data_faturamento', 'data_entrega_prevista', 'data_pcp', 'carga',
            'condicao_pagamento', 'tipo_faturamento', 'rps', 'valor_total', 'updated_at',
        ];

        foreach (array_chunk($faturados, 500) as $pedaco) {
            DB::table('pedidos')->upsert($pedaco, ['numero_pedido'], [...$colunas, 'status']);
        }

        // ⚠️ Em aberto NÃO atualiza `status`: a etapa é do 200 e ele roda depois. Pedido
        // novo nasce DESCONHECIDO (sem pill) até o 200 classificá-lo. Previsão, carga e
        // PCP também são do 200, que é mais fresco para pedido em andamento.
        $colunasAbertos = array_values(array_diff($colunas, ['data_previsao_faturamento', 'data_entrega_prevista', 'data_pcp', 'carga']));
        foreach (array_chunk($abertos, 500) as $pedaco) {
            DB::table('pedidos')->upsert($pedaco, ['numero_pedido'], $colunasAbertos);
        }

        // Estava faturado e voltou a ter item em aberto (faturamento parcial): o selo de
        // faturado não vale mais. Sem etapa até o 200 dizer qual é.
        DB::table('pedidos')
            ->whereNull('data_faturamento')
            ->where('status', StatusPedidoResolver::FATURADO)
            ->whereIn('numero_pedido', $numeros)
            ->update(['status' => StatusPedidoResolver::DESCONHECIDO]);

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
