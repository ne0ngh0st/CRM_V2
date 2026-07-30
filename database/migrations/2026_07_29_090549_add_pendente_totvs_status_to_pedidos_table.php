<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O legado não expõe um status estruturado pra pedido em aberto — só um texto livre
     * (`HISTORICO`) que nem o próprio PHP legado classifica em backend (o front dele só
     * pinta a cor por palavra-chave, sem um enum real). Em vez de inventar uma tradução
     * arbitrária de separacao/bloqueio/wms/liberado a partir desse texto, todo pedido em
     * aberto importado do TOTVS recebe esse status neutro até o Adriano incluir um código
     * de status estruturado no relatório de origem (ver docs/importacao-dados-legado.md).
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE pedidos MODIFY status ENUM('separacao', 'bloqueio', 'wms', 'liberado', 'faturado', 'pendente_totvs') DEFAULT 'separacao'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE pedidos MODIFY status ENUM('separacao', 'bloqueio', 'wms', 'liberado', 'faturado') DEFAULT 'separacao'"
        );
    }
};
