<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_wp_leads_raw', function (Blueprint $table) {
            $table->id();
            $table->dateTime('recebido_em')->index();
            $table->longText('payload_json');
            $table->string('remote_addr', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('fonte', 32)->index();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_wp_leads_raw');
    }
};
