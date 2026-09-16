<?php

namespace Tests\Concerns;

/**
 * Escreve o relatório 232 ("Pedidos emitidos") como o TOTVS o exporta, com o cabeçalho
 * real de 32 colunas, na pasta e no nome da convenção atual (mês no nome).
 */
trait EscreveRelatorio232
{
    use UsaDiretorioDeRelatorios;

    /**
     * @param  list<array<string, string>>  $linhas  valores por coluna, sobre um default válido
     */
    protected function escreverRelatorio232(array $linhas): void
    {
        $colunas = [
            'FILIAL', 'COD_USER', 'USUARIO', 'PEDIDO', 'DT_EMISSAO', 'PREV_FAT', 'PREV_ENTR',
            'DATA_PCP', 'CARGA', 'CONDPAGTO', 'COD_CLI', 'LOJA_CLI', 'CNPJ', 'CLIENTE',
            'MUNICIPIO', 'ATIVIDADE', 'COD_VENDEDOR', 'REPRES', 'SUPERVISOR', 'COD_PROD',
            'DESC_PROD', 'UND', 'PESO_LIQ', 'PRC_VENDA', 'QTDA_VENDA', 'DTA_LIBERADA',
            'VLR_TOTAL', 'DT_FATURAMENTO', 'NOTA_FISCAL', 'SERIE', 'TP_FAT', 'GERAFINANCEIRO',
        ];

        $padrao = [
            'FILIAL' => '05',
            'PEDIDO' => '994867',
            'DT_EMISSAO' => '01/09/2026',
            'PREV_FAT' => '03/09/2026',
            'PREV_ENTR' => '04/09/2026',
            'COD_CLI' => '000054',
            'LOJA_CLI' => '0066',
            'COD_VENDEDOR' => '010585',
            'COD_PROD' => 'V6045',
            'DESC_PROD' => 'PAPEL A4 75G',
            'PRC_VENDA' => '10,00',
            'QTDA_VENDA' => '10',
            'DTA_LIBERADA' => '10',
            'VLR_TOTAL' => '100,00',
            'DT_FATURAMENTO' => '05/09/2026',
            'NOTA_FISCAL' => '001123204',
            'SERIE' => '1',
            'TP_FAT' => 'PRODUTO',
        ];

        $saida = ['232 - CONSULTA DE PEDIDOS EMITIDOS META DE VENDAS.RLT'.str_repeat(';', count($colunas) - 1)];
        $saida[] = implode(';', $colunas);

        foreach ($linhas as $linha) {
            $saida[] = implode(';', array_map(fn ($c) => $linha[$c] ?? $padrao[$c] ?? '', $colunas));
        }

        $pasta = $this->diretorioTotvs.'/Pedidos emitidos';
        @mkdir($pasta, 0777, true);
        file_put_contents($pasta.'/Pedidos emitidos - 092026 - SQL.csv', implode("\n", $saida)."\n");
    }
}
