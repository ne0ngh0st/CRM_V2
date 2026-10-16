<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\Redes\MaioresRedes;
use Illuminate\Console\Command;

/**
 * Carrega as redes do mercado (Visão Diretor → Maiores redes) do JSON versionado e liga
 * clientes e leads. Idempotente. Para refazer só os vínculos: `visao-diretor:vincular-redes`.
 */
class ImportarRedesMercado extends Command
{
    protected $signature = 'visao-diretor:importar-redes
        {--arquivo= : JSON das redes (padrão: database/data/visao-diretor/redes-mercado-2026.json)}';

    protected $description = 'Carrega as redes do mercado do JSON e liga clientes e leads';

    public function handle(MaioresRedes $redes): int
    {
        ini_set('memory_limit', '1024M');

        $arquivo = $this->option('arquivo') ?: database_path('data/visao-diretor/redes-mercado-2026.json');
        $json = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

        $i = $redes->importar($json);
        $this->info("Redes: {$i['novas']} novas, {$i['atualizadas']} atualizadas, {$i['arquivadas']} arquivadas.");

        $v = $redes->reconstruir();
        $this->info("Vínculos: {$v['clientes']} clientes e {$v['leads']} leads; {$v['conflitos']} empates sem dono. {$v['ms']} ms");

        return self::SUCCESS;
    }
}
