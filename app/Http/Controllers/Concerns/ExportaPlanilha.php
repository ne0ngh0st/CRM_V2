<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Guard comum dos endpoints de exportação para Excel.
 *
 * ⚠️ POR QUE ISTO EXISTE (Regra de ouro nº 8):
 * até 2026-08-27 o par `ini_set('memory_limit') + set_time_limit()` estava copiado à mão
 * em 5 dos 9 endpoints de export — e **ausente nos outros 4**, incluindo o de Metas, que
 * é justamente o único usando `FromArray` (materializa tudo em memória, sem chunk).
 * Decisão duplicada é decisão que diverge sozinha: ninguém escolheu deixar quatro
 * endpoints sem proteção, simplesmente aconteceu.
 *
 * ⚠️ RISCO CONHECIDO EM PRODUÇÃO: `ini_set` NÃO tem efeito se o pool do PHP-FPM travar
 * esses valores via `php_admin_value` — só `php_value` é sobrescrevível em runtime. No
 * Forge eles vêm do `php.ini` (sobrescrevível), mas isso precisa ser confirmado com um
 * `phpinfo()` na primeira instância provisionada. Se estiver travado, o sintoma será
 * export grande falhando com 500 silencioso só em produção.
 */
trait ExportaPlanilha
{
    /**
     * Acima disto, exportar de forma síncrona não é viável: o ALB corta a conexão em 60 s
     * (a Carteira completa leva ~95 s) e o pico de memória passa de 500 MB.
     */
    protected const LIMITE_LINHAS_SINCRONO = 20000;

    /**
     * Prepara a requisição atual para gerar uma planilha.
     *
     * O PhpSpreadsheet mantém todas as células como objeto em memória até escrever o
     * arquivo, então `WithChunkReading` reduz idas ao banco mas não o pico de memória —
     * volume alto custa caro independentemente do chunk.
     */
    protected function prepararExport(string $recurso): void
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        // Deixa rastro de quem exportou o quê: export é a operação mais cara do sistema,
        // e sem log um pico de carga inexplicado fica sem dono.
        Log::info('Exportação de planilha iniciada', [
            'recurso' => $recurso,
            'user_id' => request()->user()?->id,
            'filtros' => request()->except(['_token', 'page']),
        ]);
    }
}
