<?php

namespace Tests\Concerns;

/**
 * Escreve o relatório 200 ("Pedidos em aberto com status") como o TOTVS o exporta.
 *
 * Existe porque mais de um teste precisa do MESMO arquivo de entrada, e o cabeçalho real
 * tem 34 colunas em ordem fixa: com uma cópia por classe de teste, a segunda envelheceria
 * sozinha quando o relatório ganhasse uma coluna, e o teste que a usasse passaria a
 * exercitar um arquivo que o TOTVS não emite mais (Regra de ouro nº 8).
 */
trait EscreveRelatorio200
{
    private string $diretorioTotvs;

    /** Diretório temporário apontado por `config('totvs.diretorio')`. Chamar no setUp. */
    protected function prepararRelatorios(): void
    {
        $this->diretorioTotvs = sys_get_temp_dir().'/totvs-teste-'.uniqid();
        mkdir($this->diretorioTotvs.'/CSV', 0777, true);
        config(['totvs.diretorio' => $this->diretorioTotvs]);
    }

    /** Chamar no tearDown. */
    protected function removerRelatorios(): void
    {
        foreach (glob($this->diretorioTotvs.'/CSV/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->diretorioTotvs.'/CSV');
        @rmdir($this->diretorioTotvs);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pedidos  [numero, historico]
     */
    protected function escreverRelatorio200(array $pedidos): void
    {
        $colunas = [
            'FILIAL', 'COD_CLI', 'LOJA_CLI', 'GRP_CLIENT', 'CNPJ', 'CLIENTE', 'FANTASIA',
            'MUNICIPIO', 'ESTADO', 'COD_REPRES', 'REPRES', 'DATA_PED', 'N_PEDIDO', 'DIGITACAO',
            'CARGA', 'DT_ENTREGA', 'DT_PREVFAT', 'DT_PCP', 'ATRASO', 'TMP_VIAGEM', 'COND_PAGTO',
            'COD_PROD', 'DESC_PROD', 'QTD_VENDA', 'QTD_LIBER', 'VLR_PEDIDO', 'EMAIL', 'CONTATO',
            'DDD', 'TELEFONE', 'DATA_HIST', 'HORA_HIST', 'USUARIO', 'HISTORICO',
        ];

        // A primeira linha é o título do relatório, sozinho — o leitor detecta por forma.
        $linhas = ['200 - PEDIDOS EM ABERTO COM STATUS.RLT'.str_repeat(';', 33)];
        $linhas[] = implode(';', $colunas);

        foreach ($pedidos as [$numero, $historico]) {
            $valores = array_fill_keys($colunas, '');
            $valores['FILIAL'] = '05';
            $valores['COD_CLI'] = '042932';
            $valores['LOJA_CLI'] = 'E004';
            $valores['COD_REPRES'] = '010585';
            $valores['DATA_PED'] = '14/08/2026';
            $valores['N_PEDIDO'] = $numero;
            $valores['DT_PREVFAT'] = '15/09/2026';
            $valores['COD_PROD'] = 'V6045';
            $valores['DESC_PROD'] = 'PAPEL A4 75G';
            $valores['QTD_VENDA'] = '10';
            $valores['QTD_LIBER'] = '10';
            $valores['VLR_PEDIDO'] = '1.000,00';
            $valores['DATA_HIST'] = '08/09/2026';
            $valores['HORA_HIST'] = '07:10:21';
            // Aspas porque o histórico do TOTVS às vezes tem `;` interno — é o caso que
            // um split ingênuo por `;` erra, e o `fgetcsv` do leitor acerta.
            $valores['HISTORICO'] = '"'.$historico.'"';

            $linhas[] = implode(';', $valores);
        }

        file_put_contents($this->diretorioTotvs.'/CSV/Pedidos abertos - SQL.csv', implode("\n", $linhas)."\n");
    }
}
