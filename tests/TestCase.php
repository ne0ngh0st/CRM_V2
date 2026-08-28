<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: aborta o teste se a conexão apontar pra um banco que não seja
     * descartável.
     *
     * Existe por causa de um incidente real (2026-08-10): as linhas de SQLite do
     * phpunit.xml estavam comentadas, então `php artisan test` rodou contra a conexão
     * padrão (`palma_v2`, o banco de desenvolvimento) e o `RefreshDatabase` fez
     * `migrate:fresh` nele — apagou os 89 mil clientes, 1 milhão de faturamentos e os
     * 201 usuários importados do legado.
     *
     * Configuração pode ser desfeita por engano; isto não. Se o teste não estiver
     * apontando pra um banco descartável, ele morre ANTES de qualquer migration rodar.
     */
    /**
     * A checagem precisa acontecer AQUI, não no setUp().
     *
     * O `setUp()` do TestCase do Laravel chama `refreshApplication()` e logo depois
     * `setUpTraits()` — e é `setUpTraits()` que dispara o migrate:fresh do
     * RefreshDatabase. Uma versão anterior desta trava rodava depois de
     * `parent::setUp()`, ou seja, depois do banco já ter sido apagado: proteção nenhuma.
     * Sobrescrevendo `refreshApplication()` a checagem cai entre a criação do app
     * (config já disponível) e os traits.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->garantirBancoDescartavel();
    }

    private function garantirBancoDescartavel(): void
    {
        $conexao = config('database.default');
        $driver = config("database.connections.{$conexao}.driver");
        $banco = (string) config("database.connections.{$conexao}.database");

        // Banco dedicado a teste — o nome precisa deixar isso explícito (palma_v2_test).
        if (str_contains(strtolower($banco), 'test')) {
            return;
        }

        // SQLite em memória também é descartável por definição.
        if ($driver === 'sqlite' && in_array($banco, [':memory:', ''], true)) {
            return;
        }

        DB::disconnect();

        throw new RuntimeException(
            "ABORTADO: os testes estão apontando para o banco '{$banco}' (conexão '{$conexao}', driver '{$driver}'), "
            .'que não é descartável. RefreshDatabase faria migrate:fresh e APAGARIA esse banco. '
            .'O alvo esperado é palma_v2_test — confira as linhas DB_CONNECTION/DB_DATABASE do phpunit.xml.',
        );
    }
}
