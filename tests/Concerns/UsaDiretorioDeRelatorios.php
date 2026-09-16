<?php

namespace Tests\Concerns;

/**
 * Diretório temporário fazendo as vezes da pasta `RELATORIOS TOTVS` do Tony.
 *
 * Saiu de dentro de `EscreveRelatorio200` quando apareceu o segundo relatório de teste
 * (o 199, em `NomeVendedorTest`): "onde o import procura o arquivo" é a mesma decisão
 * para todos eles, e com uma cópia por trait a segunda envelheceria sozinha se o layout
 * de pastas mudasse (Regra de ouro nº 8).
 */
trait UsaDiretorioDeRelatorios
{
    private string $diretorioTotvs;

    /** Chamar no setUp. */
    protected function prepararRelatorios(): void
    {
        $this->diretorioTotvs = sys_get_temp_dir().'/totvs-teste-'.uniqid();
        mkdir($this->diretorioTotvs.'/CSV', 0777, true);
        config(['totvs.diretorio' => $this->diretorioTotvs]);
    }

    /** Chamar no tearDown. */
    protected function removerRelatorios(): void
    {
        // Recursivo: o 232 grava em `Pedidos emitidos/`, não em `CSV/`.
        \Illuminate\Support\Facades\File::deleteDirectory($this->diretorioTotvs);
    }
}
