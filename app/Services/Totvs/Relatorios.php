<?php

namespace App\Services\Totvs;

use RuntimeException;

/**
 * Resolve o domínio ("clientes", "pedidos_emitidos") no arquivo em disco.
 *
 * Existe pra que nenhum comando precise saber nome de arquivo nem caminho — isso mora
 * só em `config/totvs.php` (Regra de ouro nº 8). O `config` aceita uma LISTA de nomes
 * por domínio e esta classe pega o primeiro que existir: o Tony renomeia o export de
 * vez em quando, e uma renomeação não pode exigir mudança de código.
 */
class Relatorios
{
    /**
     * @return array<string, mixed>
     */
    public static function config(string $dominio): array
    {
        $cfg = config("totvs.arquivos.{$dominio}");

        if (! is_array($cfg)) {
            $conhecidos = implode(', ', array_keys(config('totvs.arquivos', [])));
            throw new RuntimeException("Domínio de relatório desconhecido: {$dominio}. Conhecidos: {$conhecidos}");
        }

        return $cfg;
    }

    /**
     * Caminho do arquivo, ou null se nenhum dos nomes aceitos estiver na pasta.
     */
    public static function caminho(string $dominio): ?string
    {
        $diretorio = rtrim((string) config('totvs.diretorio'), '/');

        foreach ((array) self::config($dominio)['arquivo'] as $nome) {
            $caminho = $diretorio.'/'.$nome;

            if (is_readable($caminho)) {
                return $caminho;
            }
        }

        return null;
    }

    public static function abrir(string $dominio): LeitorRelatorio
    {
        $caminho = self::caminho($dominio);

        if ($caminho === null) {
            $aceitos = implode(', ', (array) self::config($dominio)['arquivo']);
            throw new RuntimeException(
                "Nenhum arquivo de '{$dominio}' encontrado em ".config('totvs.diretorio')
                .". Nomes aceitos: {$aceitos}. "
                .'Se o export mudou de nome, acrescente o novo em config/totvs.php.'
            );
        }

        $leitor = LeitorRelatorio::abrir($caminho);
        $rlt = self::config($dominio)['rlt'] ?? null;

        // Arquivo trocado não dá erro sozinho: as colunas simplesmente não batem e o
        // import grava linha vazia. O título do relatório é a checagem mais barata.
        if ($rlt !== null && ! str_contains((string) $leitor->titulo(), $rlt)) {
            throw new RuntimeException(
                'O arquivo '.basename($caminho)." não parece ser o relatório de '{$dominio}'. "
                ."Esperava o título conter \"{$rlt}\" e encontrei \"".($leitor->titulo() ?? 'nenhum').'".'
            );
        }

        return $leitor;
    }
}
