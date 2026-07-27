<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ligacoes', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->after('usuario_id')->constrained('clientes')->nullOnDelete();
            $table->index(['cliente_id', 'data_ligacao']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ligacoes', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropIndex(['cliente_id', 'data_ligacao']);
            $table->dropColumn('cliente_id');
        });
    }
};
