<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Baixa os relatórios do TOTVS do S3 e, SE algo mudou, roda os importadores na ordem
 * certa. É o que o scheduler chama — ver `routes/console.php`.
 *
 * POR QUE UM COMANDO E NÃO QUATRO LINHAS NO SCHEDULER: a ORDEM dos imports é uma regra
 * de negócio, não uma lista (ver abaixo), e ela precisa valer igual no cron, na mão pelo
 * SSH e em qualquer runbook futuro. Espalhada em três lugares, é o tipo de decisão que
 * diverge sozinha — Regra de ouro nº 8.
 *
 * ⚠️ A ORDEM IMPORTA, e cada posição tem motivo:
 *
 *   1. clientes ......... `ClientesLookup::porChave()` alimenta os dois imports de
 *      pedido. Rodar depois deles deixa pedido órfão (`cliente_id` nulo) até a próxima
 *      rodada. Medido em 2026-09-04: 109 pedidos órfãos quando o 232 rodou antes; zero
 *      depois de importar os 916 clientes novos primeiro.
 *   2. faturamento ...... independente dos outros; posição livre.
 *   3. pedidos emitidos . o 232 marca `data_faturamento`.
 *   4. pedidos abertos .. POR ÚLTIMO, de propósito. O 200 é o retrato de "o que está
 *      aberto AGORA" e, quando um pedido aparece nas duas fontes (faturamento parcial),
 *      ele é quem prevalece — ver o docblock de `ImportPedidosAbertosTotvs`. Invertendo
 *      a ordem, o 232 marcaria como faturado por cima do 200 e o pedido sumiria da tela
 *      de pendentes, que é justamente o defeito que aquele import existe para evitar.
 *
 * ⚠️ SÓ IMPORTA QUANDO O ARQUIVO MUDA. Os quatro importadores são idempotentes (upsert,
 * ou recorte que apaga e repõe a própria faixa), então rodar de novo não corrompe nada —
 * mas reescreve ~600 mil linhas de `pedido_itens` à toa, e o RDS é uma `db.t4g.small`
 * com a memória contada desde a carga do histórico. A impressão digital do diretório
 * (caminho + tamanho + mtime de cada relatório) decide.
 *
 * ⚠️ A impressão digital comparada é a da ÚLTIMA IMPORTAÇÃO BEM-SUCEDIDA, gravada em
 * disco, e não a de antes/depois do download desta rodada. A diferença aparece quando um
 * import falha no meio: com a comparação ingênua, a rodada seguinte veria "nada mudou no
 * S3" e pularia para sempre, deixando o banco parado sem nenhum erro novo aparecendo.
 */
class AtualizarDadosTotvs extends Command
{
    protected $signature = 'totvs:atualizar
        {--forcar : importa mesmo que nenhum relatório tenha mudado}
        {--sem-sync : não baixa do S3, usa o que já está em disco}';

    protected $description = 'Sincroniza os relatórios do TOTVS do S3 e importa o que mudou';

    /** Ordem deliberada — ver o docblock da classe antes de mexer. */
    private const IMPORTS = [
        'totvs:import-clientes',
        'totvs:import-faturamento',
        'totvs:import-pedidos-emitidos',
        'totvs:import-pedidos-abertos',
    ];

    public function handle(): int
    {
        $diretorio = rtrim((string) config('totvs.diretorio'), '/');

        if (! $this->option('sem-sync') && $this->call('totvs:sincronizar-s3') !== self::SUCCESS) {
            $this->error('Sincronização com o S3 falhou — nada foi importado.');

            return self::FAILURE;
        }

        $impressao = $this->impressaoDigital($diretorio);
        $marcador = $diretorio.'/.ultima-importacao';
        $anterior = is_readable($marcador) ? trim((string) file_get_contents($marcador)) : null;

        if ($impressao === $anterior && ! $this->option('forcar')) {
            $this->newLine();
            $this->line('Nenhum relatório mudou desde a última importação. Nada a fazer.');
            $this->line('Para importar mesmo assim: --forcar.');

            return self::SUCCESS;
        }

        foreach (self::IMPORTS as $comando) {
            $this->newLine();
            $this->info("── {$comando}");
            $inicio = microtime(true);

            if ($this->call($comando) !== self::SUCCESS) {
                // Aborta a corrente: seguir daqui com clientes desatualizados produz
                // pedido órfão, e com o 232 falho o 200 apagaria pedido que ainda existe.
                $this->error("{$comando} falhou. Interrompendo — o marcador NÃO foi gravado, ".
                    'então a próxima rodada tenta de novo.');

                return self::FAILURE;
            }

            $this->line(sprintf('   (%.1fs)', microtime(true) - $inicio));
        }

        // Só depois de tudo passar. Marcador gravado cedo esconderia a falha acima.
        file_put_contents($marcador, $impressao);

        $this->newLine();
        $this->info('Importação concluída.');

        return self::SUCCESS;
    }

    /**
     * Caminho + tamanho + mtime de cada relatório, em ordem estável.
     *
     * Tamanho sozinho não basta: um relatório regerado com o mesmo recorte pode ter
     * exatamente o mesmo tamanho e conteúdo diferente.
     */
    private function impressaoDigital(string $diretorio): string
    {
        if (! is_dir($diretorio)) {
            return '';
        }

        $partes = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            if (! $arquivo->isFile() || str_starts_with($arquivo->getFilename(), '.')) {
                continue;
            }

            $partes[] = $arquivo->getPathname().'|'.$arquivo->getSize().'|'.$arquivo->getMTime();
        }

        sort($partes);

        return md5(implode("\n", $partes));
    }
}
