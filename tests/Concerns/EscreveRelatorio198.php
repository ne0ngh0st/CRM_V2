<?php

namespace Tests\Concerns;

/**
 * Escreve o relatório 198 ("Faturamento equipe") como o TOTVS o exporta.
 *
 * O cabeçalho é o real, com o padding de largura fixa em `Estado      ` e `Municipio   `
 * e com as DUAS colunas de município (`Municipio`, ao lado de `Estado`, e `MUNICIPIO`,
 * depois de `CLIENTE`) — é essa duplicidade que o import precisa desambiguar.
 */
trait EscreveRelatorio198
{
    use UsaDiretorioDeRelatorios;

    /** @var list<string> */
    private array $colunas198 = [
        'FILIAL', 'EMISSAO', 'COD_CLI', 'COD_LOJA', 'COD_VENDEDOR', 'NOME_VENDEDOR', 'CNPJ',
        'Estado      ', 'Municipio   ', 'PED_CLI', 'PEDIDO', 'NTA_FISCAL', 'GRP_CLI', 'CLIENTE',
        'MUNICIPIO', 'COD_PROD', 'DES_PROD', 'QUANT', 'VLR_UNIT', 'VLR_TOTAL', 'SERIE',
        'SEGMENTO', 'COD_SUPERVISOR', 'NOME_SUPERVISOR', 'COD_DIRETOR', 'NOME_DIRETOR',
        'PESO_LIQUIDO', 'PESO_TOTAL', 'DESC_FAMILIA',
    ];

    /**
     * @param  list<array<string, string>>  $linhas  valores por coluna, sobre um default
     *                                               válido; chave sem o padding (`Estado`)
     */
    protected function escreverRelatorio198(array $linhas, bool $comFamilia = true): void
    {
        $colunas = $comFamilia
            ? $this->colunas198
            : array_values(array_diff($this->colunas198, ['DESC_FAMILIA']));

        $saida = ['198 - FATURAMENTO EQUIPE.RLT'.str_repeat(';', count($colunas) - 1)];
        $saida[] = implode(';', $colunas);

        foreach ($linhas as $linha) {
            $valores = [];

            foreach ($colunas as $coluna) {
                $valores[] = $linha[trim($coluna)] ?? $this->padrao198(trim($coluna));
            }

            $saida[] = implode(';', $valores);
        }

        file_put_contents($this->diretorioTotvs.'/CSV/FAT - SQL.csv', implode("\n", $saida)."\n");
    }

    private function padrao198(string $coluna): string
    {
        return match ($coluna) {
            'FILIAL' => '05',
            'EMISSAO' => '01/09/2026                    ',
            'COD_CLI' => '206065',
            'COD_LOJA' => '0001',
            'COD_VENDEDOR' => '000359',
            'CNPJ' => '46.371.190/0001-54',
            'Estado' => 'SP',
            'Municipio' => 'SUMARE                                   ',
            'PEDIDO' => '981464',
            'NTA_FISCAL' => '001123204',
            'CLIENTE' => 'EMPORIO DOM LUIZ LTDA',
            'MUNICIPIO' => 'SUMARE',
            'COD_PROD' => 'V28042         ',
            'DES_PROD' => 'BOBINA TS KPH BC 80X40M',
            'QUANT' => ' 750,00 ',
            'VLR_UNIT' => ' 2,91 ',
            'VLR_TOTAL' => ' 2.250,00 ',
            'SEGMENTO' => 'SUPERMERCADISTA',
            'DESC_FAMILIA' => 'BOBINA TERMICA KPH 44G                       ',
            default => '',
        };
    }
}
