<?php

namespace App\Console\Commands;

use App\Services\PowerBi\SchemaBi;
use App\Services\PowerBi\ViewsBi;
use Illuminate\Console\Command;

/**
 * Recria as views `vw_bi_*` a partir de `ViewsBi`.
 *
 * Só mexe em definição de view — nenhum dado é escrito ou apagado. Serve para depois de
 * mudar uma view sem migration nova, e para quando o banco do app muda de nome (a view
 * guarda o nome do banco).
 */
class RecriarViewsBi extends Command
{
    protected $signature = 'bi:recriar-views';

    protected $description = 'Recria as views vw_bi_* que o Power BI lê';

    public function handle(): int
    {
        $this->line('Schema: '.SchemaBi::nome());

        ViewsBi::recriar();

        foreach (ViewsBi::VIEWS as $view) {
            $this->line("  {$view}");
        }

        $this->info(count(ViewsBi::VIEWS).' views recriadas.');

        return self::SUCCESS;
    }
}
