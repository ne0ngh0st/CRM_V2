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
     * TODOS os arquivos que casam com algum padrão aceito, ordenados do mais antigo ao
     * mais recente (por `mtime`).
     *
     * ⚠️ Cada nome em `arquivo` é um padrão de `glob()` (aceita `*`), não um nome exato.
     * O relatório 232 passou a sair com o mês no nome ("Pedidos emitidos - 092026 -
     * SQL.csv"), então nome fixo quebraria todo início de mês.
     *
     * ⚠️ MAIS DE UM ARQUIVO PODE EXISTIR AO MESMO TEMPO, e "o mais recente por mtime" NÃO
     * é o mesmo que "o mês mais atual". Encontrado na prática em 03/09: o Tony gerou
     * "092026" às 13:27 e "082026" (um backfill de agosto) às 13:35 — dez minutos
     * DEPOIS. Se este método devolvesse só um arquivo escolhido por mtime, teria
     * escolhido agosto e ignorado setembro em silêncio.
     *
     * Por isso um domínio de período ('recorte' em config/totvs.php) processa TODOS os
     * candidatos, cada um fazendo merge só nas datas que ele próprio contém — a ordem
     * de processamento não importa porque cada arquivo mexe só nos seus próprios dias.
     */
    public static function todos(string $dominio): array
    {
        $diretorio = rtrim((string) config('totvs.diretorio'), '/');
        $vistos = [];

        foreach ((array) self::config($dominio)['arquivo'] as $padrao) {
            foreach (glob($diretorio.'/'.$padrao) ?: [] as $candidato) {
                $vistos[realpath($candidato) ?: $candidato] = $candidato;
            }
        }

        $arquivos = array_values($vistos);
        usort($arquivos, fn ($a, $b) => (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0));

        return $arquivos;
    }

    /**
     * Caminho do arquivo mais recente que casa com algum dos padrões aceitos, ou null se
     * nenhum casar.
     *
     * ⚠️ Só serve para domínio de RETRATO ÚNICO ('completo' em config/totvs.php), onde
     * só um arquivo faz sentido existir por vez (o cadastro de clientes inteiro, por
     * exemplo). Para domínio de período ('recorte'), usar `todos()` — ver o aviso lá:
     * "mais recente por mtime" não é "mês mais atual" quando dois meses coexistem.
     */
    public static function caminho(string $dominio): ?string
    {
        $arquivos = self::todos($dominio);

        return $arquivos === [] ? null : end($arquivos);
    }

    public static function abrir(string $dominio): LeitorRelatorio
    {
        $caminho = self::caminho($dominio);

        if ($caminho === null) {
            throw new RuntimeException(self::mensagemNenhumArquivo($dominio));
        }

        return self::abrirArquivo($caminho, $dominio);
    }

    /**
     * Abre um caminho já resolvido (por exemplo, um dos vindos de `todos()`),
     * validando o título contra o RLT esperado do domínio.
     */
    public static function abrirArquivo(string $caminho, string $dominio): LeitorRelatorio
    {
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

    private static function mensagemNenhumArquivo(string $dominio): string
    {
        $aceitos = implode(', ', (array) self::config($dominio)['arquivo']);

        return "Nenhum arquivo de '{$dominio}' encontrado em ".config('totvs.diretorio')
            .". Padrões aceitos: {$aceitos}. "
            .'Se o export mudou de nome, acrescente o novo em config/totvs.php.';
    }
}
