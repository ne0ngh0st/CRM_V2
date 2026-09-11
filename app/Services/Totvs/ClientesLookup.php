<?php

namespace App\Services\Totvs;

use Illuminate\Support\Facades\DB;

/**
 * Mapa `chave normalizada de cliente => id`, usado por todo importador que precisa
 * ligar linha de relatório a cliente já cadastrado.
 *
 * Existe porque `Normalizador::chaveCliente()` sozinho não basta: cada importador
 * precisava repetir a mesma consulta (`SELECT id, cod_cliente, loja FROM clientes`) e o
 * mesmo laço de montagem do mapa. Regra de ouro nº 8 — a consulta mora aqui uma vez.
 */
class ClientesLookup
{
    /**
     * @return array<string, int>
     */
    public static function porChave(): array
    {
        $mapa = [];

        DB::table('clientes')->select('id', 'cod_cliente', 'loja')->orderBy('id')->cursor()
            ->each(function ($c) use (&$mapa) {
                $mapa[Normalizador::chaveCliente($c->cod_cliente, $c->loja)] = $c->id;
            });

        return $mapa;
    }

    /**
     * Mapa `chave normalizada => [cod_cliente, loja] COMO ESTÃO GRAVADOS`.
     *
     * Serve a quem ESCREVE em `clientes` (os dois importadores de cliente), não a quem
     * só quer o id: antes de gravar, o importador troca a forma que veio do arquivo
     * pela forma que já está no banco.
     *
     * ⚠️ É o que impede o zero à esquerda de transformar um cliente em dois. As duas
     * origens discordam da largura do campo — o banco guarda `008710` (herança do
     * espelho do v1) e o relatório/espelho de hoje devolve `8710` —, e `upsert` casa
     * por igualdade literal, então a forma nova entra como cliente NOVO.
     *
     * ⚠️ Isto vive aqui, e não privado em cada comando, porque é a MESMA decisão ("o
     * que identifica um cliente") nos dois caminhos de import. Enquanto esteve copiado
     * em um só, aconteceu o previsível: o `totvs:import-clientes` tinha a proteção e o
     * `legado:import-clientes` não — medido em 11/09/2026, rodar o segundo sobre uma
     * base carregada pelo primeiro criou 8.408 clientes duplicados, em silêncio.
     * Cliente duplicado racha a carteira do vendedor: metade do histórico fica
     * pendurada na linha que ninguém abre.
     *
     * Custa ~10 MB de memória para 91 mil clientes, e carregar de uma vez é muito mais
     * barato que consultar por linha — seriam 92 mil SELECTs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formaGravadaPorChave(): array
    {
        $mapa = [];

        DB::table('clientes')->select('cod_cliente', 'loja')->orderBy('id')->cursor()
            ->each(function ($c) use (&$mapa) {
                $mapa[Normalizador::chaveCliente($c->cod_cliente, $c->loja)] = [$c->cod_cliente, $c->loja];
            });

        return $mapa;
    }
}
