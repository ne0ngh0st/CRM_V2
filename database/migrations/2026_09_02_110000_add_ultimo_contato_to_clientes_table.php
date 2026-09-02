<?php

use App\Services\Carteira\UltimoContatoSincronizador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desnormaliza o último contato do cliente para dentro de `clientes`.
 *
 * ⚠️ ISTO É DESNORMALIZAÇÃO DELIBERADA, e a razão é medida, não estética.
 * "Último contato" é `MAX(data_ligacao)` de `ligacoes`. Enquanto ele mora lá, ordenar
 * a Carteira por ele custa (escopo admin, 92k clientes, 281k contatos):
 *
 *   | ordenar por                                    | tempo    |
 *   |------------------------------------------------|---------:|
 *   | `data_ultima_compra` (coluna indexada daqui)    |   1,2 ms |
 *   | `MAX(data_ligacao)` via LEFT JOIN agregado      |   987 ms |
 *   | `MAX(data_ligacao)` via subconsulta correlata   | 1.179 ms |
 *
 * Quase 1 s só na ordenação estoura sozinho o orçamento de 400 ms da Regra de ouro
 * nº 9 — é o mesmo motivo que tirou 'grupo' e 'segmento' da whitelist de ordenação em
 * 2026-08-29. Como coluna daqui, ordenar volta a custar ~1 ms.
 *
 * O precedente existe e é o vizinho de coluna: `data_ultima_compra` também é valor
 * derivado (vem de `faturamentos`, via TOTVS) e mora aqui exatamente por isso.
 *
 * O ganho não é só ordenar: a listagem deixa de fazer uma consulta por página em
 * `ligacoes` (25 ms na página de pior caso) — o dado já vem na linha do cliente.
 *
 * ⚠️ Dado desnormalizado DERIVA se tiver mais de um ponto de escrita. Aqui há um só:
 * o hook `Ligacao::created()`. Não escrever nestas duas colunas de nenhum outro lugar
 * — nem em import, nem em seeder, nem em controller. Para reconstruir, use
 * `php artisan carteira:recalcular-ultimo-contato`, que roda o mesmo serviço.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dateTime('data_ultimo_contato')->nullable()->after('data_ultima_compra');
            $table->string('canal_ultimo_contato', 20)->nullable()->after('data_ultimo_contato');
            $table->index('data_ultimo_contato');
        });

        // Reusa o MESMO serviço do hook: a regra de desempate não pode existir em duas
        // versões (ver o alerta em UltimoContatoSincronizador).
        app(UltimoContatoSincronizador::class)->reconstruirTudo();
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['data_ultimo_contato']);
            $table->dropColumn(['data_ultimo_contato', 'canal_ultimo_contato']);
        });
    }

};
