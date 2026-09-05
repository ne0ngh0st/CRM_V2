<?php

namespace App\Services\Totvs;

use App\Models\TotvsImportacao;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Sincroniza os relatórios do TOTVS do S3 e, se algo mudou, roda os importadores na ordem
 * certa — registrando a rodada em `totvs_importacoes`.
 *
 * ⚠️ POR QUE UM SERVIÇO E NÃO SÓ O COMANDO: são DOIS gatilhos para a mesma coisa — o cron
 * horário (`totvs:atualizar`) e o botão da tela (`AtualizarDadosTotvsJob`). A ordem dos
 * imports e a regra de "só importa se mudou" não podem existir em duas cópias, senão o
 * botão e o cron divergem sem ninguém perceber (Regra de ouro nº 8). O comando e o job
 * são cascas finas: os dois chamam `executar()`.
 *
 * ⚠️ A ORDEM DOS IMPORTS É REGRA DE NEGÓCIO, e cada posição tem motivo:
 *
 *   1. clientes ......... alimenta o `ClientesLookup` dos dois imports de pedido. Rodar
 *      depois deles deixa pedido órfão (`cliente_id` nulo) até a rodada seguinte. Medido
 *      em 2026-09-04: 109 órfãos quando o 232 rodou antes, zero depois de importar os 916
 *      clientes novos primeiro.
 *   2. faturamento ...... independente dos outros; posição livre.
 *   3. pedidos emitidos . o 232 marca `data_faturamento`.
 *   4. pedidos abertos .. POR ÚLTIMO. O 200 é o retrato de "aberto AGORA" e prevalece
 *      quando o mesmo pedido aparece nas duas fontes (faturamento parcial) — ver o
 *      docblock de `ImportPedidosAbertosTotvs`. Invertendo, o 232 marcaria faturado por
 *      cima e o pedido sumiria da tela de pendentes, que é o defeito mais visível que
 *      este conjunto poderia ter.
 *
 * ⚠️ SÓ IMPORTA QUANDO O ARQUIVO MUDA. Os quatro importadores são idempotentes (upsert,
 * ou recorte que apaga e repõe a própria faixa), então repetir não corrompe — mas
 * reescreve ~600 mil linhas de `pedido_itens` à toa, e o RDS é uma `db.t4g.small` com a
 * memória contada desde a carga do histórico de 2018-2025.
 *
 * ⚠️ A impressão digital comparada é a da ÚLTIMA IMPORTAÇÃO BEM-SUCEDIDA, gravada em
 * disco. Comparar "antes x depois do download" pareceria equivalente e não é: quando um
 * import falha no meio, a rodada seguinte veria "nada mudou no S3" e pularia para sempre,
 * deixando o banco parado sem nenhum erro novo aparecendo.
 */
class AtualizadorTotvs
{
    /** Ordem deliberada — ver o docblock da classe antes de mexer. */
    public const IMPORTS = [
        'totvs:import-clientes',
        'totvs:import-faturamento',
        'totvs:import-pedidos-emitidos',
        'totvs:import-pedidos-abertos',
    ];

    private const ARQUIVO_MARCADOR = '.ultima-importacao';

    /**
     * Contagem de linhas exibida em `/atualizacoes`, cacheada porque `COUNT(*)` em
     * `faturamentos` custa 943 ms — o MySQL varre os 6 milhões de entradas do índice
     * (`type=index`, `Using index`), não existe contador pronto no InnoDB. Os `MAX()` da
     * mesma tela custam 0,3 ms e ficam ao vivo.
     *
     * ⚠️ É INVALIDADA AO FIM DE UMA IMPORTAÇÃO BEM-SUCEDIDA. Sem isso a tela mostraria a
     * contagem velha logo depois de atualizar — justamente o instante em que alguém está
     * olhando para conferir se funcionou.
     */
    public const CHAVE_CACHE_CONTAGENS = 'totvs:contagens-frescor';

    /**
     * ⚠️ `$rodada` já criada é o caminho do BOTÃO, e não é detalhe de implementação.
     * Quando a tela dispara, o controller cria a linha `executando` DENTRO do request e
     * só então enfileira o job. Sem isso, o redirect volta antes de o worker pegar o job,
     * `emAndamento` chega `false` no front, o acompanhamento automático nunca começa e o
     * usuário vê "nenhuma rodada registrada" logo depois de clicar — parece que o botão
     * não fez nada. Encontrado no navegador, e nenhum teste de servidor pegaria.
     *
     * O cron continua passando `null`: ali não há ninguém olhando uma tela.
     *
     * @param  'agendador'|'manual'  $origem
     * @return TotvsImportacao a rodada registrada, já com status final
     */
    public function executar(
        string $origem = 'agendador',
        ?int $userId = null,
        bool $forcar = false,
        bool $sincronizar = true,
        ?TotvsImportacao $rodada = null,
    ): TotvsImportacao {
        $rodada ??= TotvsImportacao::create([
            'status' => 'executando',
            'origem' => $origem,
            'user_id' => $userId,
            'iniciada_em' => now(),
        ]);

        $passos = [];

        try {
            if ($sincronizar) {
                $passos[] = $this->rodar('totvs:sincronizar-s3');

                if ($passos[0]['falhou']) {
                    return $this->encerrar($rodada, 'falha', $passos, 'A sincronização com o S3 falhou — nada foi importado.');
                }
            }

            $impressao = $this->impressaoDigital();

            if (! $forcar && $impressao === $this->marcadorGravado()) {
                return $this->encerrar($rodada, 'sem_mudanca', $passos);
            }

            foreach (self::IMPORTS as $comando) {
                $passo = $this->rodar($comando);
                $passos[] = $passo;

                if ($passo['falhou']) {
                    // Aborta a corrente: seguir daqui com clientes desatualizados produz
                    // pedido órfão, e com o 232 falho o 200 apagaria pedido que ainda
                    // existe. O marcador NÃO é gravado, então a próxima rodada tenta de
                    // novo em vez de considerar o trabalho feito.
                    return $this->encerrar($rodada, 'falha', $passos, "{$comando} falhou — a corrente foi interrompida.");
                }
            }

            // Só depois de tudo passar. Marcador gravado cedo esconderia a falha acima.
            $this->gravarMarcador($impressao);

            // A tela é aberta justamente para conferir se funcionou; contagem velha ali
            // seria pior que contagem nenhuma.
            Cache::forget(self::CHAVE_CACHE_CONTAGENS);

            return $this->encerrar($rodada, 'sucesso', $passos);
        } catch (Throwable $e) {
            Log::error('totvs:atualizar falhou', ['erro' => $e->getMessage()]);

            return $this->encerrar($rodada, 'falha', $passos, $e->getMessage());
        }
    }

    /**
     * @return array{comando: string, segundos: float, falhou: bool, saida: string}
     */
    private function rodar(string $comando): array
    {
        $buffer = new BufferedOutput;
        $inicio = microtime(true);
        $codigo = Artisan::call($comando, [], $buffer);

        return [
            'comando' => $comando,
            'segundos' => round(microtime(true) - $inicio, 1),
            'falhou' => $codigo !== 0,
            // A saída dos importadores é curta (contagens por arquivo), mas o aviso de
            // "relatório com colunas erradas" é uma linha longa — corta para o JSON não
            // virar um despejo.
            'saida' => mb_substr(trim($buffer->fetch()), 0, 4000),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $passos
     */
    private function encerrar(TotvsImportacao $rodada, string $status, array $passos, ?string $erro = null): TotvsImportacao
    {
        $rodada->update([
            'status' => $status,
            'concluida_em' => now(),
            'passos' => $passos,
            'erro' => $erro,
        ]);

        return $rodada->refresh();
    }

    public function diretorio(): string
    {
        return rtrim((string) config('totvs.diretorio'), '/');
    }

    private function caminhoMarcador(): string
    {
        return $this->diretorio().'/'.self::ARQUIVO_MARCADOR;
    }

    public function marcadorGravado(): ?string
    {
        $caminho = $this->caminhoMarcador();

        return is_readable($caminho) ? trim((string) file_get_contents($caminho)) : null;
    }

    private function gravarMarcador(string $impressao): void
    {
        @file_put_contents($this->caminhoMarcador(), $impressao);
    }

    /**
     * Caminho + tamanho + mtime de cada relatório, em ordem estável.
     *
     * ⚠️ Tamanho sozinho não bastaria: um relatório regerado com o mesmo recorte pode ter
     * exatamente o mesmo tamanho e conteúdo diferente.
     */
    public function impressaoDigital(): string
    {
        $diretorio = $this->diretorio();

        if (! is_dir($diretorio)) {
            return '';
        }

        $partes = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($diretorio, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $arquivo) {
            /** @var SplFileInfo $arquivo */
            if (! $arquivo->isFile() || str_starts_with($arquivo->getFilename(), '.')) {
                continue;
            }

            $partes[] = $arquivo->getPathname().'|'.$arquivo->getSize().'|'.$arquivo->getMTime();
        }

        sort($partes);

        return md5(implode("\n", $partes));
    }
}
