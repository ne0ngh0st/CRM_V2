<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leads MODIFY origem ENUM('sistema', 'manual', 'wordpress') NOT NULL DEFAULT 'sistema'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leads MODIFY origem ENUM('sistema', 'manual') NOT NULL DEFAULT 'sistema'");
    }
};
