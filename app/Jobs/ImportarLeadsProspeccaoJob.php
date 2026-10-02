<?php

namespace App\Jobs;

use App\Models\LeadImportacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * O botão "Importar leads" / "Simular" da /atualizacoes: baixa do S3 o que o time de
 * prospecção subiu e roda `totvs:import-leads` (com `--dry-run` na simulação).
 *
 * ⚠️ FILA, e não request, pelos mesmos dois motivos do `AtualizarDadosTotvsJob`: quem tem
 * os arquivos é o nó do worker (`storage/app/totvs` na app-2 — no request, o ALB poderia
 * mandar para a app-1, que não tem os CSVs), e baixar + importar passa dos 2 s que a
 * Regra de ouro nº 9 aceita de forma síncrona.
 *
 * ⚠️ O sincronismo baixa só o que mudou de tamanho; rodar junto da corrente horária
 * (`totvs:atualizar`, que também sincroniza) no máximo baixa o mesmo arquivo duas vezes.
 * A corrente horária NÃO importa leads, então os dois não disputam tabela.
 */
class ImportarLeadsProspeccaoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $rodadaId,
        public readonly bool $simulacao,
    ) {
    }

    /** Uma rodada de leads por vez: dois cliques quase simultâneos passam pelo guarda do controller. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('leads-importar'))->expireAfter(1800)->dontRelease()];
    }

    public int $timeout = 1200;

    /** Sem retentativa: falha de import quase sempre é a planilha, e repetir dá o mesmo. */
    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('totvs:sincronizar-s3');

        Artisan::call('totvs:import-leads', array_filter([
            '--rodada' => $this->rodadaId,
            '--dry-run' => $this->simulacao,
        ]));
    }

    /**
     * O comando já marca a rodada como falha quando ELE quebra. Isto cobre o que quebra
     * fora dele (o sincronismo do S3), senão a tela mostraria "executando" até travar.
     */
    public function failed(?Throwable $e): void
    {
        LeadImportacao::query()->whereKey($this->rodadaId)->where('status', 'executando')->update([
            'status' => 'falhou',
            'erro' => mb_substr((string) $e?->getMessage(), 0, 2000) ?: 'A rodada falhou na fila.',
            'concluida_em' => now(),
        ]);
    }
}
