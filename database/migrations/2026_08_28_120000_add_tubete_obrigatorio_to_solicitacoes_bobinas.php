<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_bobinas', function (Blueprint $table) {
            $table->string('tubete_obrigatorio', 3)->nullable()->after('metragem');
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_bobinas', function (Blueprint $table) {
            $table->dropColumn('tubete_obrigatorio');
        });
    }
};
