<?php

use App\Services\PowerBi\ViewsBi;
use Illuminate\Database\Migrations\Migration;

/**
 * `vw_bi_fato_leads` passa a ter a origem PROSPECCAO (leads dos CSVs da pasta Leads/).
 * Sem recriar, eles cairiam no ELSE e sairiam como SITE. A SQL mora em `ViewsBi`.
 */
return new class extends Migration
{
    public function up(): void
    {
        ViewsBi::recriar();
    }

    public function down(): void
    {
        ViewsBi::recriar();
    }
};
