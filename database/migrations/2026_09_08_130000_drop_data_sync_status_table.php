<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove `data_sync_status`, que descrevia uma funcionalidade que nunca existiu.
 *
 * ⚠️ NENHUM CÓDIGO DE PRODUÇÃO ESCREVIA NESTA TABELA — só o `DataSyncStatusSeeder`. Ela
 * alimentava a pill "Sistema:" do Painel, que por isso passou de 10/08 a 08/09/2026
 * anunciando "Desatualizado" com o sistema em pleno uso e o dado sendo importado de hora
 * em hora. O engano é o mesmo de `users.last_activity_at` (badge "0 online agora",
 * 31/08): uma coluna com aparência de alimentada, que o Eloquent nunca preenchia.
 *
 * ⚠️ Dropar, e não deixar órfã, é a parte que impede a reincidência. Tabela morta com
 * nome plausível é convite para alguém — inclusive um agente — voltar a ler dela daqui a
 * três meses achando que é a fonte de verdade. A pill agora sai de `FrescorDoDado`, que
 * mede a data da última nota e do último pedido, sem tabela de marcação nenhuma.
 *
 * O `down()` recria a estrutura, mas não os dados de seed — que eram fictícios de
 * qualquer forma (`now()->subHours(4)`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('data_sync_status');
    }

    public function down(): void
    {
        Schema::create('data_sync_status', function (Blueprint $table) {
            $table->id();
            $table->string('tabela')->unique();
            $table->timestamp('last_synced_at');
        });
    }
};
