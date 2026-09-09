<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Exportacao;
use App\Services\Exportacao\CatalogoDeExportacoes;
use App\Services\Exportacao\GeradorDeExportacao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * O caminho único de exportação para Excel — usado pelos nove endpoints do sistema.
 *
 * ⚠️ POR QUE ISTO EXISTE (Regra de ouro nº 8):
 * até 2026-08-27 o par `ini_set('memory_limit') + set_time_limit()` estava copiado à mão
 * em 5 dos 9 endpoints de export — e **ausente nos outros 4**, incluindo o de Metas, que
 * é justamente o único usando `FromArray` (materializa tudo em memória, sem chunk).
 * Decisão duplicada é decisão que diverge sozinha: ninguém escolheu deixar quatro
 * endpoints sem proteção, simplesmente aconteceu.
 *
 * ⚠️ Desde 2026-09-09 o trait não devolve mais o arquivo: ele REGISTRA a exportação e
 * devolve o link. Toda planilha passa a existir no disco e a aparecer em "Meus
 * downloads" — antes, oito das nove sumiam no histórico do navegador de quem clicou, e a
 * nona só era alcançável pela notificação do sino, que some ao ser lida.
 *
 * ⚠️ RISCO CONHECIDO EM PRODUÇÃO: `ini_set` NÃO tem efeito se o pool do PHP-FPM travar
 * esses valores via `php_admin_value` — só `php_value` é sobrescrevível em runtime.
 * Validado em produção em 2026-08-28 (512M→1024M, 60s→300s via FPM); se um dia virarem
 * `php_admin_value`, o sintoma será export grande falhando com 500 silencioso.
 */
trait ExportaPlanilha
{
    /**
     * Pede uma planilha e devolve a resposta certa para cada desfecho.
     *
     * ⚠️ NÃO existe mais um `Excel::download()` por controller. Quem escolhe entre gerar
     * agora e enfileirar é o volume medido (`GeradorDeExportacao::pedir`), então o
     * controller não sabe — e não deve saber — qual dos dois vai acontecer. Ele só diz
     * QUAL recurso está exportando; o resto é do catálogo.
     *
     * A resposta é sempre um redirect com flash, nunca o binário: é o que permite ao
     * botão reagir aos dois desfechos sem sair da SPA.
     */
    protected function entregarPlanilha(string $recurso, Request $request): RedirectResponse
    {
        $this->prepararExport($recurso);

        $exportacao = app(GeradorDeExportacao::class)->pedir($recurso, $request, $request->user());
        $rotulo = app(CatalogoDeExportacoes::class)->rotulo($recurso);

        if ($exportacao->processando()) {
            return back()->with('exportacao', [
                'estado' => 'enfileirada',
                'rotulo' => $rotulo,
                'linhas' => $exportacao->linhas,
            ]);
        }

        if ($exportacao->status === Exportacao::STATUS_ERRO) {
            return back()->with('exportacao', [
                'estado' => 'erro',
                'rotulo' => $rotulo,
            ]);
        }

        /*
         * ⚠️ O caminho síncrono NÃO notifica: o arquivo desce no navegador em seguida, e um
         * sino dizendo "está pronto aquilo que você acabou de baixar" é ruído — ruído é o
         * que faz as pessoas pararem de olhar o sino. Travado por teste.
         */
        return back()->with('exportacao', [
            'estado' => 'pronta',
            'rotulo' => $rotulo,
            'linhas' => $exportacao->linhas,
            // O botão dispara o download com esta URL; a checagem de dono continua sendo
            // feita lá, na rota, e não aqui.
            'url' => route('exportacoes.download', $exportacao->id, false),
        ]);
    }

    /**
     * Prepara a requisição atual para gerar uma planilha.
     *
     * O PhpSpreadsheet mantém todas as células como objeto em memória até escrever o
     * arquivo, então `WithChunkReading` reduz idas ao banco mas não o pico de memória —
     * volume alto custa caro independentemente do chunk. Daí o corte por linhas de
     * `config('exportacoes.limite_linhas_sincrono')`: acima dele nem este teto adianta.
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
