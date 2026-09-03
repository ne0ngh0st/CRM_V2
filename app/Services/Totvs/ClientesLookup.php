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
}
