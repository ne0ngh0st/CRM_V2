<?php

namespace App\Console\Commands;

use App\Exports\RedesMercadoVinculosExport;
use App\Services\VisaoDiretor\Redes\RelatorioDeRedes;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel de revisão: o que o motor de vínculo (`VinculoDeRedes`) ligaria a cada rede do
 * mercado, ANTES de a página Maiores redes existir (decisão do Tony, 2026-10-16: "antes
 * de construir eu preciso de um excel"). Só lê o banco e grava em
 * `storage/app/private/relatorios/` (disco `local`). Não grava vínculo nenhum.
 */
class RedesMercadoVinculos extends Command
{
    protected $signature = 'visao-diretor:redes-vinculos
        {--arquivo= : JSON das redes (padrão: database/data/visao-diretor/redes-mercado-2026.json)}';

    protected $description = 'Excel de revisão dos vínculos das redes do mercado (clientes e leads)';

    public function handle(RelatorioDeRedes $relatorio): int
    {
        ini_set('memory_limit', '1024M');

        $arquivo = $this->option('arquivo') ?: database_path('data/visao-diretor/redes-mercado-2026.json');
        $json = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

        $inicio = microtime(true);
        $r = $relatorio->montar($json);
        $caminho = 'relatorios/maiores-redes-vinculos-'.now()->format('Y-m-d').'.xlsx';
        Excel::store(new RedesMercadoVinculosExport($r), $caminho, 'local');

        $s = $r['resumo'];
        $this->info(sprintf(
            '%d redes: %d com cliente (%d ativas), %d sem vínculo nenhum. %d clientes e %d leads ligados; %d desempates, %d empates sem dono. %.0f s',
            $s['redes'], $s['com_cliente'], $s['ativas'], $s['sem_vinculo'], $s['clientes'], $s['leads'], $s['desempates'], $s['conflitos'], microtime(true) - $inicio,
        ));
        $this->info('Planilha: '.storage_path('app/private/'.$caminho));

        return self::SUCCESS;
    }
}
