<?php

namespace App\Services\Totvs;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Lê a primeira aba de um xlsx linha a linha, sem montar a planilha em memória.
 *
 * Existe porque o PhpSpreadsheet guarda cada célula como objeto: o cadastro de produtos
 * do TOTVS (005, ~95 mil linhas × 12 colunas) levou 105 s e 786 MB só para abrir. Aqui a
 * aba é percorrida em streaming (XMLReader dentro do zip) e só os textos compartilhados
 * ficam em memória.
 *
 * Só lê VALOR: sem fórmula, data formatada ou estilo. Data chega como número serial do
 * Excel. Serve para relatório do TOTVS exportado como tabela crua, não para planilha
 * montada à mão.
 */
final class LeitorXlsx
{
    /**
     * @return Generator<int, array<int, string>> cada linha com as colunas indexadas a
     *                                            partir de 0 (A = 0); célula vazia não aparece
     */
    public static function linhas(string $caminho): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('não é um xlsx válido: '.basename($caminho));
        }

        try {
            $aba = self::caminhoDaPrimeiraAba($zip);
            $compartilhados = self::textosCompartilhados($zip);
        } finally {
            $zip->close();
        }

        $xml = new XMLReader;

        if (! $xml->open('zip://'.$caminho.'#'.$aba)) {
            throw new RuntimeException("aba {$aba} não encontrada em ".basename($caminho));
        }

        $linha = null;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row') {
                if ($xml->isEmptyElement) {
                    yield [];
                } else {
                    $linha = [];
                }

                continue;
            }

            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->name === 'row') {
                yield $linha ?? [];
                $linha = null;

                continue;
            }

            if ($linha === null || $xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'c' || $xml->isEmptyElement) {
                continue;
            }

            $coluna = self::indiceDaColuna((string) $xml->getAttribute('r'));
            $tipo = $xml->getAttribute('t');
            $celula = $xml->expand();

            if ($tipo === 'inlineStr') {
                $valor = $celula->textContent;
            } else {
                $v = $celula->getElementsByTagName('v')->item(0)?->textContent;

                if ($v === null) {
                    continue;
                }

                $valor = $tipo === 's' ? ($compartilhados[(int) $v] ?? '') : $v;
            }

            $linha[$coluna] = $valor;
        }

        $xml->close();
    }

    private static function caminhoDaPrimeiraAba(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            throw new RuntimeException('xlsx sem xl/workbook.xml');
        }

        $planilha = new SimpleXMLElement($workbook);
        $primeira = $planilha->sheets->sheet[0] ?? null;
        $id = $primeira?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

        foreach ((new SimpleXMLElement($rels))->Relationship as $rel) {
            if ((string) $rel['Id'] === (string) $id) {
                $alvo = ltrim((string) $rel['Target'], '/');

                return str_starts_with($alvo, 'xl/') ? $alvo : 'xl/'.$alvo;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function textosCompartilhados(ZipArchive $zip): array
    {
        $conteudo = $zip->getFromName('xl/sharedStrings.xml');

        if ($conteudo === false) {
            return [];
        }

        $xml = new XMLReader;
        $xml->XML($conteudo);
        $textos = [];

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'si') {
                // textContent junta os trechos de texto formatado (<r><t>…</t></r>).
                $textos[] = $xml->expand()->textContent;
            }
        }

        $xml->close();

        return $textos;
    }

    /** "A1" → 0, "L95737" → 11, "AA3" → 26. */
    private static function indiceDaColuna(string $referencia): int
    {
        $indice = 0;

        foreach (str_split(preg_replace('/\d+$/', '', strtoupper($referencia))) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }
}
