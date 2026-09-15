<?php

namespace Tests\Concerns;

/**
 * Escreve os relatórios `210 - CADASTRO DE CLIENTES` e `199 - ULTIMO FATURAMENTO` como o
 * TOTVS os exporta — cabeçalho de verdade, na ordem de verdade.
 *
 * ⚠️ O cabeçalho do 199 repete `Nome` (o do cliente e o do vendedor) e `Descricao` (a do
 * grupo e a do segmento). É justamente por isso que ele é escrito INTEIRO aqui em vez de
 * só com as colunas de interesse: um fixture com as colunas "que importam" não reproduz a
 * desambiguação (`Nome_2`, `Descricao_2`) e deixaria passar exatamente o erro que ela
 * existe para evitar — escrever razão social do cliente na coluna Vendedor.
 */
trait EscreveRelatoriosDeCliente
{
    use UsaDiretorioDeRelatorios;

    private const COLUNAS_210 = [
        'Codigo', 'Loja', 'Nome', 'N Fantasia', 'CNPJ/CPF', 'Vendedor', 'Endereco',
        'Estado', 'Municipio', 'E-Mail NF-e', 'DDD', 'Telefone', 'CEP', 'Grp.Vendas', 'Segmento 1',
    ];

    private const COLUNAS_199 = [
        'COD_CLIENT', 'LOJA', 'CNPJ', 'Grp.Vendas', 'Descricao', 'Nome', 'N Fantasia',
        'MUNICIPIO', 'E-Mail NF-e', 'DDD', 'Telefone', 'Telefone 2', 'Segmento 1', 'Descricao',
        'Codigo', 'Nome', 'Nome Reduzid', 'COD_SUPER', 'RAZ_SUPER', 'FANT_SUPER', 'COD_DIR',
        'RAZ_DIR', 'FANT_DIR', 'NFISCAL', 'DT_FAT', 'VLR_TOTAL', 'CEP', 'Endereco', 'Estado',
    ];

    /**
     * @param  list<array{cod: string, loja: string, nome: string, vendedor: string}>  $clientes
     */
    protected function escreverRelatorio210(array $clientes): void
    {
        $linhas = [$this->titulo('210 - CADASTRO DE CLIENTES.RLT', self::COLUNAS_210)];
        $linhas[] = implode(';', self::COLUNAS_210);

        foreach ($clientes as $c) {
            $valores = array_fill_keys(self::COLUNAS_210, '');
            $valores['Codigo'] = $c['cod'];
            $valores['Loja'] = $c['loja'];
            $valores['Nome'] = $c['nome'];
            $valores['CNPJ/CPF'] = $c['cnpj'] ?? '16729628000162';
            $valores['Vendedor'] = $c['vendedor'];
            $valores['Estado'] = 'SP';
            $valores['Grp.Vendas'] = '009998';
            $valores['Segmento 1'] = '000101';

            $linhas[] = implode(';', $valores);
        }

        $this->gravar('Clientes - SQL.csv', $linhas);
    }

    /**
     * @param  list<array{cod: string, loja: string, cliente: string, codVendedor: string, nomeVendedor: string, reduzido?: string}>  $linhasDeFaturamento
     */
    protected function escreverRelatorio199(array $linhasDeFaturamento): void
    {
        $linhas = [$this->titulo('199 - ULTIMO FATURAMENTO CLIENTE.RLT', self::COLUNAS_199)];
        $linhas[] = implode(';', self::COLUNAS_199);

        foreach ($linhasDeFaturamento as $f) {
            // Posicional de propósito: as colunas repetidas não sobrevivem a um mapa por
            // nome, e é a POSIÇÃO que o leitor usa para desambiguar.
            $valores = array_fill(0, count(self::COLUNAS_199), '');
            $valores[0] = $f['cod'];
            $valores[1] = $f['loja'];
            $valores[3] = '009998';
            $valores[4] = 'CLIENTES DIVERSOS';
            $valores[5] = $f['cliente'];            // Nome  → do CLIENTE
            $valores[12] = '000101';
            $valores[13] = 'SUPERMERCADISTA';
            $valores[14] = $f['codVendedor'];       // Codigo → do VENDEDOR
            $valores[15] = $f['nomeVendedor'];      // Nome_2 → do VENDEDOR
            $valores[16] = $f['reduzido'] ?? '';
            $valores[24] = $f['dtFat'] ?? '10/09/2026';

            $linhas[] = implode(';', $valores);
        }

        $this->gravar('Ultimo faturamento - SQL.csv', $linhas);
    }

    /** @param  list<string>  $colunas */
    private function titulo(string $nome, array $colunas): string
    {
        return $nome.str_repeat(';', count($colunas) - 1);
    }

    /** @param  list<string>  $linhas */
    private function gravar(string $arquivo, array $linhas): void
    {
        file_put_contents($this->diretorioTotvs.'/CSV/'.$arquivo, implode("\n", $linhas)."\n");
    }
}
