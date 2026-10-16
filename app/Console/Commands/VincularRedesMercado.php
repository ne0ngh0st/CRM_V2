<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\Redes\MaioresRedes;
use Illuminate\Console\Command;

/**
 * Refaz os vínculos do motor das redes do mercado e os contadores (Visão Diretor →
 * Maiores redes). Não toca vínculo manual nem remoção feitos pela tela. Roda no fim do
 * `totvs:atualizar` (`AtualizadorTotvs::DERIVADOS`).
 */
class VincularRedesMercado extends Command
{
    protected $signature = 'visao-diretor:vincular-redes';

    protected $description = 'Refaz os vínculos automáticos das redes do mercado';

    public function handle(MaioresRedes $redes): int
    {
        ini_set('memory_limit', '1024M');

        $v = $redes->reconstruir();
        $this->info("Vínculos: {$v['clientes']} clientes e {$v['leads']} leads; {$v['conflitos']} empates sem dono. {$v['ms']} ms");

        return self::SUCCESS;
    }
}
