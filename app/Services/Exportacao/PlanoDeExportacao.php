<?php

namespace App\Services\Exportacao;

/**
 * O que vai ser escrito no arquivo, quantas linhas são e como ele se chama.
 *
 * `linhas` é contado ANTES de gerar porque é ele que decide o caminho: até o limite de
 * `config('exportacoes.limite_linhas_sincrono')` a planilha sai na própria requisição;
 * acima disso vai para a fila. Sem a contagem prévia não há como escolher — e escolher
 * errado significa ou uma aba travada por 19 s, ou uma espera de sino para um arquivo
 * que sairia em meio segundo.
 */
final readonly class PlanoDeExportacao
{
    public function __construct(
        /** O objeto de export do maatwebsite (FromQuery, FromArray, …). */
        public object $planilha,
        /** Prefixo do arquivo: `leads` vira `leads-2026-09-09-143012.xlsx`. */
        public string $nomeBase,
        public int $linhas,
    ) {}
}
