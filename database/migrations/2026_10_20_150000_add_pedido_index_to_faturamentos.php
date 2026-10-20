<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para achar a nota de um pedido em `faturamentos` (DataFaturamentoPelaNota).
 *
 * Sem ele, o cruzamento dos pedidos que saíram do 200 com as notas varre a tabela
 * inteira: 34 s em produção em 2026-10-20, e isso passaria a rodar em toda importação.
 *
 * Idempotente: dev e produção podem chegar aqui com o índice já criado à mão.
 */
return new class extends Migration
{
    private const INDICE = 'faturamentos_pedido_index';

    public function up(): void
    {
        if ($this->existe()) {
            return;
        }

        Schema::table('faturamentos', function (Blueprint $table) {
            $table->index('pedido', self::INDICE);
        });
    }

    public function down(): void
    {
        if (! $this->existe()) {
            return;
        }

        Schema::table('faturamentos', function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }

    private function existe(): bool
    {
        return DB::select('SHOW INDEX FROM faturamentos WHERE Key_name = ?', [self::INDICE]) !== [];
    }
};
