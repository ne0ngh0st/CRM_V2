<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Baixa os relatórios do TOTVS do S3 para o disco local, de onde os `totvs:import-*`
 * leem — é a metade de recepção de `infra/enviar-relatorios-totvs.sh` (que roda na
 * máquina do Tony e sobe os CSVs de `RELATORIOS TOTVS/` para `s3://.../totvs/`).
 *
 * ⚠️ POR QUE ISTO EXISTE, E NÃO UM MOUNT DE REDE DO ONEDRIVE. A pasta com os relatórios
 * vive no OneDrive do Tony — não dá para montar isso direto numa instância AWS (SMB pela
 * internet é inseguro e normalmente bloqueado, e dependeria da máquina dele estar
 * ligada). O S3 já é a ponte que este projeto usa para outros arquivos (fotos de faca,
 * exports); aqui só reaproveita a mesma. O bucket e a permissão já existiam — nada novo
 * foi criado na AWS para isto.
 *
 * ⚠️ A ESTRUTURA DE PASTAS É PRESERVADA de propósito: um objeto em
 * `totvs/CSV/Clientes - SQL.csv` vira `storage/app/totvs/CSV/Clientes - SQL.csv`. É essa
 * mesma estrutura relativa que `config/totvs.php` espera (os padrões de arquivo lá são
 * `CSV/...`, `Pedidos emitidos/...`) — local (via Docker) e produção (via este comando)
 * enxergam os relatórios pelo mesmo caminho relativo, só a origem física muda.
 *
 * ⚠️ `TOTVS_RELATORIOS_DIR` em produção tem que apontar para o caminho ABSOLUTO desta
 * pasta (`/var/www/crm/storage/app/totvs`), e `storage/app/totvs` está no `.gitignore`
 * para sobreviver ao `git clean -fd` do `deploy.sh` — sem isso, cada deploy apagaria os
 * relatórios baixados.
 *
 * Não baixa de novo o que já bate em tamanho: cada relatório é dezenas de MB, e rodar
 * este comando sem nada novo no S3 não deveria custar nada além de listar os objetos.
 */
class SincronizarRelatoriosTotvsS3 extends Command
{
    private const PREFIXO_S3 = 'totvs/';

    protected $signature = 'totvs:sincronizar-s3 {--forcar : baixa de novo mesmo se o tamanho bater}';

    protected $description = 'Baixa do S3 os relatórios do TOTVS que o Tony enviou de RELATORIOS TOTVS/';

    public function handle(): int
    {
        $forcar = (bool) $this->option('forcar');
        $diretorioLocal = rtrim((string) config('totvs.diretorio'), '/');

        $chaves = Storage::disk('s3')->allFiles(rtrim(self::PREFIXO_S3, '/'));

        if ($chaves === []) {
            $this->error('Nenhum arquivo em s3://.../'.self::PREFIXO_S3.'. Rode infra/enviar-relatorios-totvs.sh na máquina do Tony primeiro.');

            return self::FAILURE;
        }

        $baixados = 0;
        $puladosIguais = 0;

        foreach ($chaves as $chave) {
            $relativo = substr($chave, strlen(self::PREFIXO_S3));
            $caminhoLocal = $diretorioLocal.'/'.$relativo;

            if (! $forcar && is_file($caminhoLocal) && filesize($caminhoLocal) === Storage::disk('s3')->size($chave)) {
                $puladosIguais++;

                continue;
            }

            $this->line('  baixando '.$relativo.' ('.$this->tamanho(Storage::disk('s3')->size($chave)).')...');

            $diretorioDestino = dirname($caminhoLocal);
            if (! is_dir($diretorioDestino)) {
                mkdir($diretorioDestino, 0755, true);
            }

            // Stream, não `get()`: um relatório já passou de 100 MB (Pedidos emitidos),
            // e ler o arquivo inteiro como string só para gravar de novo dobraria a
            // memória usada à toa.
            $origem = Storage::disk('s3')->readStream($chave);
            $destino = fopen($caminhoLocal, 'w');

            if ($origem === null || $destino === false) {
                $this->error("  falhou ao abrir stream para {$relativo}.");

                continue;
            }

            stream_copy_to_stream($origem, $destino);
            fclose($destino);
            fclose($origem);

            $baixados++;
        }

        $this->info("Baixados: {$baixados}");
        if ($puladosIguais > 0) {
            $this->line("Já estavam iguais (mesmo tamanho): {$puladosIguais}");
        }
        $this->line('Pronto para: php artisan totvs:inspecionar');

        return self::SUCCESS;
    }

    private function tamanho(int $bytes): string
    {
        return $bytes > 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
