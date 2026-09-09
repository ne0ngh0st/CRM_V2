<?php

namespace App\Jobs;

use App\Services\Exportacao\GeradorDeExportacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * @deprecated Substituído por GerarExportacaoJob, que serve a qualquer recurso.
 *
 * ⚠️ EXISTE SÓ PARA SOBREVIVER AO DEPLOY, e por isso não tem lógica própria.
 * Um job de exportação vive minutos na fila. Se houvesse um export da Carteira em voo no
 * instante em que a versão nova subisse, o worker tentaria desserializar uma classe que
 * não existe mais e o payload cairia em `failed_jobs` — o usuário esperaria para sempre
 * um arquivo que ninguém mais vai gerar, sem erro visível em lugar nenhum.
 *
 * Pode ser apagado com segurança depois que a fila tiver drenado (algumas horas após o
 * deploy). Nada no código novo despacha esta classe.
 */
class GerarExportacaoCarteiraJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        private readonly int $exportacaoId,
    ) {}

    public function handle(GeradorDeExportacao $gerador): void
    {
        (new GerarExportacaoJob($this->exportacaoId))->handle($gerador);
    }

    public function failed(?Throwable $e): void
    {
        (new GerarExportacaoJob($this->exportacaoId))->failed($e);
    }
}
