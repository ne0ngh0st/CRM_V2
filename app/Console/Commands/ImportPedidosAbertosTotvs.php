<?php

namespace App\Console\Commands;

use App\Services\Pedidos\StatusPedidoResolver;
use App\Services\Totvs\ClientesLookup;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Atualiza a ETAPA dos pedidos em aberto a partir do relatório 200.
 *
 * 🥇 DESDE 2026-09-25 ESTE COMANDO NÃO CRIA, NÃO APAGA E NÃO MEXE EM VALOR DE PEDIDO.
 * Quem diz quais pedidos existem, quanto valem e se estão faturados é o 232
 * ({@see ImportPedidosEmitidosTotvs}). O 200 traz também pedido que não gera
 * financeiro (remessa de almoxarifado virtual, transferência) — R$ 12,7 mi só em
 * setembro/2026 —, e era por ele que o total do CRM e do BI não batia com o Excel do
 * 232. Aqui ele só atualiza, nos pedidos EM ABERTO que o 232 trouxe: etapa
 * (`status`), texto cru do TOTVS, previsão de faturamento/entrega, PCP e carga.
 *
 * Pedido que está no 200 e não no CRM é contado e avisado, não gravado: ou é remessa,
 * ou é mais novo que o último 232 — e aparece quando o 232 for gerado de novo.
 *
 * ⚠️ O STATUS DO PEDIDO SAI DO `HISTORICO`, e este comentário já disse o contrário.
 * Ele mandava não adivinhar status a partir da frase, porque a redação poderia mudar —
 * e o efeito foi todo pedido em aberto entrar como `pendente_totvs`, deixando a tela com
 * uma coluna de valor único. Contados os moldes no arquivo real (2026-09-09), 99,7% dos
 * 3.478 pedidos caem em 11 frases estáveis, então a tradução passou a ser feita por
 * {@see StatusPedidoResolver} — que é onde mora o mapa e a defesa contra a redação nova.
 *
 * ⚠️ O QUE SOBROU DAQUELE CUIDADO, e é o que não pode ser removido: este comando CONTA
 * quantos movimentos não foram reconhecidos e IMPRIME exemplos. Sem esse aviso, o TOTVS
 * mudar uma frase viraria uma coluna esvaziando aos poucos, sem erro nenhum. O texto cru
 * fica gravado em `pedidos.historico_totvs` de qualquer jeito, então nada se perde.
 */
class ImportPedidosAbertosTotvs extends Command
{
    protected $signature = 'totvs:import-pedidos-abertos
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa os pedidos em aberto do relatório 200 do TOTVS, direto do arquivo';

    public function __construct(private readonly StatusPedidoResolver $status)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leitor = Relatorios::abrir('pedidos_abertos');
        $leitor->exigirColunas([
            'FILIAL', 'COD_CLI', 'LOJA_CLI', 'COD_REPRES', 'N_PEDIDO', 'DATA_PED', 'DT_PREVFAT',
            'DT_ENTREGA', 'DT_PCP', 'CARGA', 'COND_PAGTO', 'COD_PROD', 'DESC_PROD',
            'QTD_VENDA', 'QTD_LIBER', 'VLR_PEDIDO', 'DATA_HIST', 'HORA_HIST', 'HISTORICO',
        ]);

        $clientePorChave = ClientesLookup::porChave();

        $cabecalhos = [];
        $itens = [];
        $linhas = 0;
        $semCliente = 0;

        // Movimentos que nenhum molde reconheceu, contados por frase normalizada para o
        // aviso do fim: uma redação nova aparece como um número grande numa linha só.
        $naoReconhecidos = [];

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
                    'filial' => Normalizador::filial($linha['FILIAL']),
                    'cod_vendedor' => Normalizador::codigoVendedor($linha['COD_REPRES']) ?? '',
                    'data_pedido' => Normalizador::data($linha['DATA_PED']),
                    'data_previsao_faturamento' => Normalizador::data($linha['DT_PREVFAT']),
                    'data_faturamento' => null,
                    'data_entrega_prevista' => Normalizador::data($linha['DT_ENTREGA']),
                    'data_pcp' => Normalizador::data($linha['DT_PCP']),
                    'carga' => Normalizador::valorOuNull($linha['CARGA']),
                    'condicao_pagamento' => Normalizador::valorOuNull($linha['COND_PAGTO']),
                    'status' => $status = $this->status->resolver($linha['HISTORICO']),
                    'historico_totvs' => Normalizador::valorOuNull($linha['HISTORICO']),
                    'historico_em' => Normalizador::dataHora($linha['DATA_HIST'], $linha['HORA_HIST']),
                    'valor_total' => 0,
                ];

                if ($status === StatusPedidoResolver::DESCONHECIDO && trim($linha['HISTORICO']) !== '') {
                    $frase = trim($linha['HISTORICO']);
                    $naoReconhecidos[$frase] = ($naoReconhecidos[$frase] ?? 0) + 1;
                }
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

        // ⚠️ Nunca `array_keys()` cru contra `numero_pedido` — ver Normalizador::numerosDePedido().
        $numeros = Normalizador::numerosDePedido($cabecalhos);

        $emAberto = [];
        foreach (array_chunk($numeros, 2000) as $pedaco) {
            foreach (DB::table('pedidos')->whereNull('data_faturamento')->whereIn('numero_pedido', $pedaco)->pluck('numero_pedido') as $n) {
                $emAberto[(string) $n] = true;
            }
        }

        $atualizar = array_intersect_key($cabecalhos, $emAberto);
        $foraDoCrm = array_diff_key($cabecalhos, $emAberto);

        if ($dryRun) {
            $this->info('[dry-run] Atualizaria a etapa de '.number_format(count($atualizar), 0, ',', '.').' pedidos em aberto.');
        } else {
            DB::transaction(function () use ($atualizar) {
                $this->gravar($atualizar);
            });

            $this->info('Etapa atualizada em '.number_format(count($atualizar), 0, ',', '.').' pedidos em aberto.');
        }

        if ($foraDoCrm !== []) {
            $this->line(sprintf(
                'No 200 e não no CRM (não gravados): %s pedidos, R$ %s',
                number_format(count($foraDoCrm), 0, ',', '.'),
                number_format(array_sum(array_column($foraDoCrm, 'valor_total')), 2, ',', '.')
            ));
            $this->line('  → remessa sem financeiro (não é venda) ou pedido mais novo que o último 232.');
        }

        if ($semCliente > 0) {
            $this->warn('Pedidos sem cliente correspondente no CRM: '.number_format($semCliente, 0, ',', '.'));
        }

        if ($semData > 0) {
            $this->warn("Ignorados (sem data de pedido): {$semData}");
        }

        $this->resumirClassificacao($cabecalhos, $naoReconhecidos);

        return self::SUCCESS;
    }

    /**
     * Mostra em que etapa os pedidos caíram e denuncia molde que o CRM não conhece.
     *
     * ⚠️ ESTE AVISO É A ÚNICA DEFESA contra o TOTVS mudar a redação de um movimento. Se
     * "COM BLOQUEIO DE ESTOQUE" virar outra frase amanhã, mil pedidos deixam de ter pill
     * na tela — e nada quebra, nada fica vermelho, nenhum teste falha. O que denuncia é
     * este bloco imprimindo uma frase desconhecida com contagem alta. Se ele sair daqui,
     * a feature passa a degradar em silêncio, que é o defeito que ela nasceu evitando.
     *
     * @param  array<string, array<string, mixed>>  $cabecalhos
     * @param  array<string, int>  $naoReconhecidos
     */
    private function resumirClassificacao(array $cabecalhos, array $naoReconhecidos): void
    {
        if ($cabecalhos === []) {
            return;
        }

        $porStatus = array_count_values(array_column($cabecalhos, 'status'));
        $total = count($cabecalhos);

        $this->line('');
        $this->line('Etapa no TOTVS:');

        foreach (StatusPedidoResolver::todos() as $status) {
            $quantos = $porStatus[$status] ?? 0;

            if ($quantos === 0) {
                continue;
            }

            // ⚠️ `str_pad` conta BYTES: "Rejeição de crédito" tem 19 caracteres e 22
            // bytes, e a coluna sai torta justamente nas etapas acentuadas. O padding
            // aqui é medido em caracteres.
            $rotulo = $this->status->rotulo($status) ?? 'Sem classificação';
            $rotulo .= str_repeat(' ', max(0, 22 - mb_strlen($rotulo)));

            $this->line(sprintf(
                '  %s %6s  (%s%%)',
                $rotulo,
                number_format($quantos, 0, ',', '.'),
                number_format($quantos * 100 / $total, 1, ',', '.')
            ));
        }

        if ($naoReconhecidos === []) {
            return;
        }

        arsort($naoReconhecidos);

        $this->line('');
        $this->warn('Movimentos que o CRM não reconheceu: '.number_format(array_sum($naoReconhecidos), 0, ',', '.'));
        $this->line('  → esses pedidos ficam SEM pill de status na tela (o texto do TOTVS continua gravado).');
        $this->line('  → se algum abaixo tiver contagem alta, é redação nova: ensinar o molde ao StatusPedidoResolver.');

        foreach (array_slice($naoReconhecidos, 0, 10, true) as $frase => $quantos) {
            $this->line(sprintf('     %5s × %s', $quantos, mb_strimwidth($frase, 0, 90, '…')));
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $cabecalhos  só pedidos já em aberto no CRM
     */
    private function gravar(array $cabecalhos): void
    {
        $agora = now();

        $lote = [];
        foreach ($cabecalhos as $numero => $cab) {
            $lote[] = $cab + ['numero_pedido' => (string) $numero, 'created_at' => $agora, 'updated_at' => $agora];
        }

        // Todos existem (filtrados antes), então o upsert só ATUALIZA — e só as colunas
        // de andamento. Valor, cliente, vendedor e itens são do 232.
        foreach (array_chunk($lote, 500) as $pedaco) {
            DB::table('pedidos')->upsert($pedaco, ['numero_pedido'], [
                'data_previsao_faturamento', 'data_entrega_prevista', 'data_pcp', 'carga',
                'status', 'historico_totvs', 'historico_em', 'updated_at',
            ]);
        }
    }
}
