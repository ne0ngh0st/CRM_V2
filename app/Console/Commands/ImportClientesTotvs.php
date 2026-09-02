<?php

namespace App\Console\Commands;

use App\Services\Totvs\LeitorRelatorio;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa clientes lendo o relatório do TOTVS direto do arquivo — sem passar pelo
 * PALMA legado. Equivalente ao `legado:import-clientes`, mesma regra de negócio, outra
 * fonte. Os dois convivem até o caminho por arquivo estar rodando em produção.
 *
 * Lê DOIS relatórios, e é de propósito que seja um comando só:
 *
 *   210 - CADASTRO DE CLIENTES  → a linha de cliente
 *   199 - ULTIMO FATURAMENTO    → data da última compra, e as descrições de grupo e
 *                                 de segmento (o cadastro só traz os CÓDIGOS)
 *
 * Separar em dois comandos deixaria importar cliente com código de grupo que ainda não
 * existe na tabela de lookup — o filtro da Carteira mostraria o código cru em vez do
 * nome, e ninguém ligaria uma coisa à outra.
 *
 * ⚠️ Se o Último Faturamento não estiver na pasta, `data_ultima_compra` fica FORA do
 * update. Sem esse cuidado, um upsert com o mapa vazio gravaria null em cima da data de
 * todo mundo — e é dessa data que sai o status ativo/inativando/inativo da carteira
 * inteira. O comando avisa e segue, em vez de zerar o indicador em silêncio.
 */
class ImportClientesTotvs extends Command
{
    protected $signature = 'totvs:import-clientes
        {--chunk=1000 : tamanho do lote de upsert}
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa clientes do relatório 210 do TOTVS, direto do arquivo';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $ultimaCompra = [];
        $temUltimoFaturamento = Relatorios::caminho('ultimo_faturamento') !== null;

        if ($temUltimoFaturamento) {
            $ultimoFat = Relatorios::abrir('ultimo_faturamento');
            $ultimoFat->exigirColunas(['COD_CLIENT', 'LOJA', 'DT_FAT', 'Grp.Vendas', 'Descricao', 'Segmento 1', 'Descricao_2']);

            [$ultimaCompra, $grupos, $segmentos] = $this->lerUltimoFaturamento($ultimoFat);

            $this->line(sprintf(
                '199 - Último faturamento: %s clientes com data de compra, %d grupos, %d segmentos.',
                number_format(count($ultimaCompra), 0, ',', '.'),
                count($grupos),
                count($segmentos)
            ));

            if (! $dryRun) {
                $this->gravarLookup('grupos_cliente', $grupos);
                $this->gravarLookup('segmentos', $segmentos);
            }
        } else {
            $this->warn('199 - Último faturamento não está na pasta.');
            $this->warn('  → data_ultima_compra, grupos e segmentos NÃO serão tocados nesta rodada.');
            $this->warn('  → é o que evita apagar a data de compra de todo mundo (status da carteira sai dela).');
        }

        $clientes = Relatorios::abrir('clientes');
        $clientes->exigirColunas(['Codigo', 'Loja', 'Nome', 'CNPJ/CPF', 'Vendedor', 'Grp.Vendas', 'Segmento 1']);

        $atualizaveis = [
            'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_segmento', 'cod_grupo',
            'estado', 'cep', 'telefone', 'email', 'updated_at',
        ];

        if ($temUltimoFaturamento) {
            $atualizaveis[] = 'data_ultima_compra';
        }

        $chavesGravadas = $this->chavesJaGravadas();

        $agora = now();
        $lote = [];
        $total = 0;
        $novos = 0;
        $ignorados = 0;
        $semCompra = 0;

        foreach ($clientes->linhas() as $linha) {
            $codCliente = $linha['Codigo'];
            $loja = $linha['Loja'];

            if ($codCliente === '' || $loja === '') {
                $ignorados++;

                continue;
            }

            // Grava com a chave que JÁ está no banco quando o cliente existe. O
            // relatório escreve a loja como `1209` e o banco guarda `001209` (herança
            // do espelho do v1) — usar a forma do arquivo criaria um segundo cliente.
            $chave = Normalizador::chaveCliente($codCliente, $loja);
            if (isset($chavesGravadas[$chave])) {
                [$codCliente, $loja] = $chavesGravadas[$chave];
            } else {
                $novos++;
            }

            $data = $ultimaCompra[Normalizador::chaveCliente($codCliente, $loja)] ?? null;
            if ($temUltimoFaturamento && $data === null) {
                $semCompra++;
            }

            $lote[] = [
                'cod_cliente' => $codCliente,
                'loja' => $loja,
                'cnpj' => Normalizador::documento($linha['CNPJ/CPF']),
                'razao_social' => $linha['Nome'],
                'nome_fantasia' => Normalizador::valorOuNull($linha['N Fantasia'] ?? ''),
                'cod_vendedor' => Normalizador::valorOuNull($linha['Vendedor']),
                'cod_segmento' => Normalizador::codigo($linha['Segmento 1']),
                'cod_grupo' => Normalizador::codigo($linha['Grp.Vendas']),
                'estado' => Normalizador::valorOuNull($linha['Estado'] ?? ''),
                'cep' => Normalizador::valorOuNull($linha['CEP'] ?? ''),
                'telefone' => Normalizador::telefone($linha['DDD'] ?? '', $linha['Telefone'] ?? ''),
                'email' => Normalizador::email($linha['E-Mail NF-e'] ?? ''),
                'data_ultima_compra' => $data,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                $total += $this->gravarLote($lote, $atualizaveis, $dryRun);
                $lote = [];

                if ($total % 20000 === 0) {
                    $this->line('  '.number_format($total, 0, ',', '.').' processados...');
                }
            }
        }

        if ($lote !== []) {
            $total += $this->gravarLote($lote, $atualizaveis, $dryRun);
        }

        $this->info(($dryRun ? '[dry-run] ' : '').'Clientes importados/atualizados: '.number_format($total, 0, ',', '.'));

        if ($novos > 0) {
            $this->line('Cadastros novos (não existiam no banco): '.number_format($novos, 0, ',', '.'));
        }

        if ($ignorados > 0) {
            $this->warn("Ignorados (sem código ou loja): {$ignorados}");
        }

        if ($semCompra > 0) {
            $this->line('Sem data de última compra (nunca faturaram): '.number_format($semCompra, 0, ',', '.'));
        }

        return self::SUCCESS;
    }

    /**
     * Mapa `chave normalizada => [cod_cliente, loja] como estão gravados`.
     *
     * Custa ~10 MB de memória para 91 mil clientes e evita o upsert errar o alvo por
     * causa de zero à esquerda. Carregar de uma vez é muito mais barato que consultar
     * por linha: seriam 92 mil SELECTs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function chavesJaGravadas(): array
    {
        $mapa = [];

        DB::table('clientes')->select('cod_cliente', 'loja')->orderBy('id')->cursor()
            ->each(function ($c) use (&$mapa) {
                $mapa[Normalizador::chaveCliente($c->cod_cliente, $c->loja)] = [$c->cod_cliente, $c->loja];
            });

        return $mapa;
    }

    /**
     * Uma passada só pelos 88 mil registros, extraindo as três coisas que o relatório
     * carrega. Ler o arquivo três vezes seria três varreduras de 79 MB.
     *
     * @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,string>}
     */
    private function lerUltimoFaturamento(LeitorRelatorio $leitor): array
    {
        $ultimaCompra = [];
        $grupos = [];
        $segmentos = [];

        foreach ($leitor->linhas() as $linha) {
            $data = Normalizador::data($linha['DT_FAT']);
            if ($data !== null) {
                $ultimaCompra[Normalizador::chaveCliente($linha['COD_CLIENT'], $linha['LOJA'])] = $data;
            }

            // ⚠️ `Descricao` (a primeira) é a do GRUPO e `Descricao_2` a do SEGMENTO —
            // o relatório repete o nome da coluna. Trocar as duas não dá erro: a
            // Carteira passa a mostrar nome de grupo onde deveria ter segmento.
            $codGrupo = Normalizador::codigo($linha['Grp.Vendas']);
            $nomeGrupo = trim($linha['Descricao']);
            if ($codGrupo !== null && $nomeGrupo !== '' && ! isset($grupos[$codGrupo])) {
                $grupos[$codGrupo] = $nomeGrupo;
            }

            $codSegmento = Normalizador::codigo($linha['Segmento 1']);
            $nomeSegmento = trim($linha['Descricao_2']);
            if ($codSegmento !== null && $nomeSegmento !== '' && ! isset($segmentos[$codSegmento])) {
                $segmentos[$codSegmento] = $nomeSegmento;
            }
        }

        return [$ultimaCompra, $grupos, $segmentos];
    }

    /**
     * Upsert por código, nunca delete: código que sumir do relatório continua na tabela.
     * Cliente antigo pode apontar para um segmento que o TOTVS não usa mais, e apagar a
     * descrição faria a tela voltar a exibir o código cru.
     *
     * @param  array<string,string>  $mapa
     */
    private function gravarLookup(string $tabela, array $mapa): void
    {
        $agora = now();
        $linhas = [];

        foreach ($mapa as $codigo => $nome) {
            $linhas[] = ['codigo' => $codigo, 'nome' => $nome, 'created_at' => $agora, 'updated_at' => $agora];
        }

        foreach (array_chunk($linhas, 500) as $pedaco) {
            DB::table($tabela)->upsert($pedaco, ['codigo'], ['nome', 'updated_at']);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $lote
     * @param  list<string>  $atualizaveis
     */
    private function gravarLote(array $lote, array $atualizaveis, bool $dryRun): int
    {
        if (! $dryRun) {
            DB::table('clientes')->upsert($lote, ['cod_cliente', 'loja'], $atualizaveis);
        }

        return count($lote);
    }
}
