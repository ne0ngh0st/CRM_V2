<?php

namespace App\Services\Receita;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Acesso à base aberta de CNPJ da Receita (compartilhamento público do Nextcloud do
 * SERPRO, `config('receita.base')`): qual é o mês mais recente, baixar um zip e ler as
 * linhas dele. Quem usa: `receita:importar-situacoes` (situação dos nossos CNPJs) e
 * `visao-diretor:cnpj-das-redes` (o CNPJ de cada rede do mercado).
 *
 * Layout dos arquivos (sem cabeçalho, `;`, aspas, latin1):
 * - Estabelecimentos: 0 cnpj_basico · 1 ordem · 2 dv · 3 matriz(1)/filial(2) ·
 *   4 nome fantasia · 5 situação · 6 data da situação · … · 11 CNAE principal · … · 19 UF
 * - Empresas: 0 cnpj_basico · 1 razão social · 2 natureza jurídica · 3 qualificação ·
 *   4 capital social ("1000,00") · 5 porte
 */
class BaseAberta
{
    public function referenciaMaisRecente(): string
    {
        $resposta = $this->webdav()->withHeaders(['Depth' => '1'])
            ->send('PROPFIND', config('receita.base.webdav').'/')->throw();

        preg_match_all('#/(\d{4}-\d{2})/</d:href>#', $resposta->body(), $m);

        if ($m[1] === []) {
            throw new RuntimeException('Não achei nenhum mês publicado na base aberta da Receita.');
        }

        sort($m[1]);

        return end($m[1]);
    }

    /** Baixa `{$tipo}{$indice}.zip` do mês para `config('receita.base.diretorio')`. */
    public function baixar(string $referencia, int $indice, string $tipo = 'Estabelecimentos'): string
    {
        $pasta = config('receita.base.diretorio');
        @mkdir($pasta, 0775, true);
        $destino = "{$pasta}/{$tipo}{$indice}.zip";
        $url = config('receita.base.webdav')."/{$referencia}/{$tipo}{$indice}.zip";

        try {
            $this->webdav()->timeout(3600)->withOptions(['sink' => $destino])->get($url)->throw();
        } catch (Throwable $e) {
            @unlink($destino);
            throw new RuntimeException("Falha ao baixar {$url}: {$e->getMessage()}", 0, $e);
        }

        return $destino;
    }

    /**
     * As linhas cruas (latin1, com o "\n") do arquivo dentro do zip, em streaming.
     *
     * @return Generator<int, string>
     */
    public function linhas(string $caminho): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true || $zip->numFiles < 1) {
            throw new RuntimeException("Não consegui abrir o zip da Receita: {$caminho}");
        }

        $stream = $zip->getStream($zip->getNameIndex(0));
        if ($stream === false) {
            $zip->close();
            throw new RuntimeException("Zip da Receita sem conteúdo legível: {$caminho}");
        }

        try {
            while (($linha = fgets($stream)) !== false) {
                yield $linha;
            }
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    /** Os 8 primeiros dígitos, pela posição fixa (`"12345678";…`) — sem `str_getcsv`. */
    public static function raiz(string $linha): string
    {
        return $linha[0] === '"' && ($linha[9] ?? '') === '"'
            ? substr($linha, 1, 8)
            : str_pad(str_getcsv($linha, ';', '"', '')[0] ?? '', 8, '0', STR_PAD_LEFT);
    }

    private function webdav(): PendingRequest
    {
        // Compartilhamento público do Nextcloud da Receita: o token do link é o usuário.
        return Http::withBasicAuth((string) config('receita.base.token'), '')->connectTimeout(30);
    }
}
