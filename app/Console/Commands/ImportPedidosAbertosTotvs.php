<?php

namespace App\Console\Commands;

use App\Services\Pedidos\StatusPedidoResolver;
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
            'COD_CLI', 'LOJA_CLI', 'COD_REPRES', 'N_PEDIDO', 'DATA_PED', 'DT_PREVFAT',
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
                    'cod_vendedor' => $linha['COD_REPRES'],
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
                'condicao_pagamento', 'status', 'historico_totvs', 'historico_em',
                'valor_total', 'updated_at',
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
