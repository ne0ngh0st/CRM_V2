<?php

namespace App\Console\Commands;

use App\Exports\FiliaisForaDoSegmentoExport;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Gera a planilha das filiais cadastradas no TOTVS fora do segmento da própria empresa,
 * para o Cadastro corrigir lá (o CRM é só leitura de cliente — Regra de ouro nº 4).
 *
 * O "segmento da empresa" é a mesma régua que decide em que aba da Visão Diretor ela
 * aparece (`UniversoDoSegmento::criarSegmentoDaEmpresa()`). Grava em
 * `storage/app/private/relatorios/` (disco `local`); não envia nada a ninguém.
 */
class FiliaisForaDoSegmento extends Command
{
    protected $signature = 'visao-diretor:filiais-fora-do-segmento';

    protected $description = 'Planilha das filiais com segmento diferente do da própria empresa (para o Cadastro)';

    public function handle(UniversoDoSegmento $universo): int
    {
        ini_set('memory_limit', '1024M');

        $linhas = $universo->filiaisForaDoSegmento();
        $caminho = 'relatorios/filiais-fora-do-segmento-'.now()->format('Y-m-d').'.xlsx';
        Excel::store(new FiliaisForaDoSegmentoExport($linhas), $caminho, 'local');

        $empate = count(array_filter($linhas, fn ($l) => (int) $l['empate'] > 1));
        $this->info(sprintf(
            '%s filiais fora do segmento da empresa (%s em empate, a conferir). Planilha: %s',
            number_format(count($linhas), 0, ',', '.'),
            number_format($empate, 0, ',', '.'),
            storage_path('app/private/'.$caminho),
        ));

        return self::SUCCESS;
    }
}
