<?php

namespace App\Services\Totvs;

use Generator;
use RuntimeException;

/**
 * Lê um relatório do TOTVS exportado em CSV, direto do arquivo.
 *
 * É a base do import que NÃO passa pelo PALMA legado. Hoje o caminho do dado é
 * TOTVS → relatório → importador do v1 → espelho `autopel01_homolog` → `legado:import-*`.
 * Com este leitor, o v2 lê o mesmo relatório de origem e o v1 sai do meio.
 *
 * Os arquivos vivem em `RELATORIOS TOTVS/CSV/` na máquina do Tony (ver `config/totvs.php`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE O FORMATO TEM DE TRAIÇOEIRO — tudo conferido nos arquivos reais, não suposto:
 *
 * 1. A PRIMEIRA LINHA NÃO É O CABEÇALHO. É o nome do relatório, sozinho na primeira
 *    célula: `210 - CADASTRO DE CLIENTES.RLT;;;;;;`. O cabeçalho é a segunda linha.
 *    Exceção: o `base_marco` (leads) começa direto no cabeçalho — daí a detecção ser
 *    por forma (só a primeira célula preenchida), não por posição fixa.
 *
 * 2. NOMES DE COLUNA SE REPETEM. No `199 - ULTIMO FATURAMENTO` a coluna `Descricao`
 *    aparece DUAS vezes (uma é a descrição do grupo de vendas, a outra a do segmento)
 *    e `Nome` também. Mapear por nome sem tratar isso lê a coluna errada em silêncio,
 *    e é exatamente o par `Segmento1`/`Descricao1` de que saem as tabelas `segmentos` e
 *    `grupos_cliente`. Aqui a segunda ocorrência vira `Descricao_2`, a terceira
 *    `Descricao_3`, preservando a ordem do arquivo.
 *
 * 3. É UTF-8 COM BOM, não cp1252 — MAS CAMPO A CAMPO ISSO NÃO SE SUSTENTA. Os arquivos
 *    falham ao decodificar como cp1252 (conferido em `FAT - SQL.csv` e `base_marco`), e
 *    ainda assim carregam bytes cp1252 SOLTOS em valores isolados: 210 deles no
 *    `199 - ULTIMO FATURAMENTO`, todos NBSP (`A0` sem o `C2`), vindos de texto colado no
 *    cadastro do TOTVS. Um só derrubou a rodada inteira de produção em 2026-09-08, com
 *    `1366 Incorrect string value` no meio do import — não no começo, quando seria fácil
 *    relacionar. Por isso todo campo passa por `Normalizador::textoUtf8()`, e por isso a
 *    conferência de encoding é do ARQUIVO, nunca prova sobre o CAMPO.
 *
 * 4. NOME E VALOR VÊM COM PADDING (`Codigo      `), resquício de export de largura
 *    fixa. Os dois são aparados.
 *
 * 5. Os arquivos são grandes (o `META VENDA` tem 98 MB). Por isso `linhas()` é um
 *    generator sobre `fgetcsv`, nunca um `array` inteiro — é o mesmo motivo pelo qual
 *    o histórico de faturamento precisou de um script Python em vez de PhpSpreadsheet.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class LeitorRelatorio
{
    private const SEPARADOR = ';';

    /** @var list<string> */
    private array $cabecalho;

    private function __construct(
        private readonly string $caminho,
        private readonly ?string $titulo,
        private readonly int $linhaCabecalho,
        array $cabecalho,
    ) {
        $this->cabecalho = $cabecalho;
    }

    public static function abrir(string $caminho): self
    {
        if (! is_readable($caminho)) {
            throw new RuntimeException("Relatório não encontrado ou sem permissão de leitura: {$caminho}");
        }

        $handle = self::abrirHandle($caminho);

        $primeira = fgetcsv($handle, 0, self::SEPARADOR);
        if ($primeira === false || $primeira === null) {
            fclose($handle);
            throw new RuntimeException("Relatório vazio: {$caminho}");
        }

        $primeira = self::limparBom($primeira);

        if (self::pareceTitulo($primeira)) {
            $titulo = trim((string) $primeira[0]);
            $cabecalho = fgetcsv($handle, 0, self::SEPARADOR);
            $linhaCabecalho = 2;

            if ($cabecalho === false || $cabecalho === null) {
                fclose($handle);
                throw new RuntimeException("Relatório só tem o título, sem cabeçalho: {$caminho}");
            }
        } else {
            $titulo = null;
            $cabecalho = $primeira;
            $linhaCabecalho = 1;
        }

        fclose($handle);

        return new self($caminho, $titulo, $linhaCabecalho, self::nomearColunas($cabecalho));
    }

    /**
     * Percorre as linhas de dado, cada uma como array associativo pelo nome da coluna.
     *
     * @return Generator<int, array<string, string>>
     */
    public function linhas(): Generator
    {
        $handle = self::abrirHandle($this->caminho);

        for ($i = 0; $i < $this->linhaCabecalho; $i++) {
            fgetcsv($handle, 0, self::SEPARADOR);
        }

        $numero = $this->linhaCabecalho;

        try {
            while (($campos = fgetcsv($handle, 0, self::SEPARADOR)) !== false) {
                $numero++;

                if ($campos === null || $campos === [null]) {
                    continue;
                }

                // O TOTVS às vezes fecha o arquivo com uma linha em branco, e o rodapé de
                // alguns relatórios vem com menos colunas que o cabeçalho. Nem uma nem
                // outra é dado; descartar aqui evita que virem registro com tudo vazio.
                if (count($campos) < count($this->cabecalho)) {
                    continue;
                }

                $linha = [];
                foreach ($this->cabecalho as $pos => $nome) {
                    // `textoUtf8` ANTES do `trim`: o byte cp1252 solto derruba o import
                    // inteiro no MySQL (ver o docblock lá), e o NBSP que ele vira espaço
                    // é justamente o padding que o `trim` precisa alcançar.
                    $linha[$nome] = trim(Normalizador::textoUtf8($campos[$pos] ?? ''));
                }

                yield $numero => $linha;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    public function cabecalho(): array
    {
        return $this->cabecalho;
    }

    public function titulo(): ?string
    {
        return $this->titulo;
    }

    public function temColuna(string $nome): bool
    {
        return in_array($nome, $this->cabecalho, true);
    }

    /**
     * Confere que o relatório é o esperado antes de importar qualquer coisa.
     *
     * Sem isso, apontar o comando para o arquivo errado não dá erro: as colunas
     * simplesmente não batem e o import grava linha vazia. Falhar cedo e dizer o que
     * faltou é mais barato que descobrir depois no dado.
     *
     * @param  list<string>  $obrigatorias
     */
    public function exigirColunas(array $obrigatorias): void
    {
        $faltando = array_values(array_diff($obrigatorias, $this->cabecalho));

        if ($faltando !== []) {
            throw new RuntimeException(
                'O relatório '.basename($this->caminho).' não tem as colunas esperadas: '
                .implode(', ', $faltando).'. Colunas encontradas: '.implode(', ', $this->cabecalho)
            );
        }
    }

    /**
     * @return resource
     */
    private static function abrirHandle(string $caminho)
    {
        $handle = fopen($caminho, 'r');

        if ($handle === false) {
            throw new RuntimeException("Não consegui abrir o relatório: {$caminho}");
        }

        return $handle;
    }

    /**
     * Título é a linha em que só a primeira célula tem conteúdo — o TOTVS escreve
     * `232 - CONSULTA DE PEDIDOS EMITIDOS...RLT` seguido de separadores vazios. Um
     * cabeçalho de verdade tem várias células preenchidas, então a forma distingue os
     * dois sem depender do arquivo ter ou não a linha.
     *
     * @param  list<string|null>  $campos
     */
    private static function pareceTitulo(array $campos): bool
    {
        if (count($campos) < 2 || trim((string) $campos[0]) === '') {
            return false;
        }

        foreach (array_slice($campos, 1) as $campo) {
            if (trim((string) $campo) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string|null>  $campos
     * @return list<string|null>
     */
    private static function limparBom(array $campos): array
    {
        if (isset($campos[0])) {
            $campos[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $campos[0]);
        }

        return $campos;
    }

    /**
     * Apara o padding e desambigua nome repetido (`Descricao`, `Descricao_2`, ...),
     * mantendo a posição original — ver o item 2 do cabeçalho desta classe.
     *
     * @param  list<string|null>  $cabecalho
     * @return list<string>
     */
    private static function nomearColunas(array $cabecalho): array
    {
        $nomes = [];
        $vistos = [];

        foreach ($cabecalho as $pos => $bruto) {
            // Mesmo tratamento das células de dado: um NBSP no nome da coluna faria
            // `exigirColunas` acusar `Descricao` faltando num arquivo que a tem.
            $nome = trim(Normalizador::textoUtf8($bruto));

            if ($nome === '') {
                $nome = 'coluna_'.($pos + 1);
            }

            $vistos[$nome] = ($vistos[$nome] ?? 0) + 1;
            $nomes[] = $vistos[$nome] === 1 ? $nome : $nome.'_'.$vistos[$nome];
        }

        return $nomes;
    }
}
