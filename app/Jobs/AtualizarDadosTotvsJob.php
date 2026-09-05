<?php

namespace App\Jobs;

use App\Models\TotvsImportacao;
use App\Services\Totvs\AtualizadorTotvs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Gatilho de tela para `AtualizadorTotvs` — o que o botão "Atualizar agora" dispara.
 *
 * ⚠️ VAI PARA A FILA, e não roda no request, porque a corrente completa leva ~2 minutos
 * (medido em produção em 2026-09-04). O orçamento da Regra de ouro nº 9 para uma ação de
 * escrita é 500 ms; qualquer coisa acima de 2 s não pode ser síncrona. A tela dispara,
 * volta na hora e passa a acompanhar pelo registro em `totvs_importacoes`.
 *
 * ⚠️ O WORKER É QUEM TEM OS ARQUIVOS. `storage/app/totvs` existe na app-2 — o nó do
 * worker e do scheduler —, e é exatamente por isso que este trabalho PRECISA ser um job:
 * se rodasse no request, cairia no nó que o ALB escolhesse, e na app-1 encontraria o
 * diretório vazio. Não é só latência; é correção. Se um dia o worker passar a rodar em
 * mais de um nó, `totvs:sincronizar-s3` baixa do SET S3 no nó onde rodar, então continua
 * funcionando — só custa o download.
 */
class AtualizarDadosTotvsJob implements ShouldQueue
{
    use Queueable;

    /**
     * `$rodadaId` é a linha `executando` que o controller já criou no request — ver o
     * docblock de `AtualizadorTotvs::executar()`. Nulo quando não veio da tela.
     */
    public function __construct(
        public readonly ?int $userId = null,
        public readonly bool $forcar = false,
        public readonly ?int $rodadaId = null,
    ) {
    }

    /**
     * Uma rodada por vez. O controller já recusa disparar quando existe uma em andamento,
     * mas isso é uma checagem de leitura seguida de escrita: dois cliques quase
     * simultâneos passam pelos dois. Aqui o lock é atômico (Redis).
     *
     * `expireAfter` maior que a duração máxima esperada, senão o lock cai no meio de uma
     * rodada lenta e a seguinte entra junto.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('totvs-atualizar'))->expireAfter(2400)->dontRelease()];
    }

    /** ~2 min no caminho normal; uma reimportação de todos os meses chega perto de 10. */
    public int $timeout = 1800;

    /**
     * Sem retentativa automática: se falhou, o motivo costuma ser o arquivo (relatório
     * com coluna faltando, recorte errado) e repetir dá o mesmo resultado. O erro fica
     * visível na tela e o Tony decide.
     */
    public int $tries = 1;

    public function handle(AtualizadorTotvs $atualizador): void
    {
        $atualizador->executar(
            origem: 'manual',
            userId: $this->userId,
            forcar: $this->forcar,
            // Se a linha sumiu (expurgo, banco recriado), o serviço cria outra — o
            // trabalho não pode deixar de acontecer por causa do registro.
            rodada: $this->rodadaId !== null ? TotvsImportacao::find($this->rodadaId) : null,
        );
    }
}
