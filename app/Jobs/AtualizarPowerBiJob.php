<?php

namespace App\Jobs;

use App\Models\TotvsImportacao;
use App\Services\PowerBi\PowerBiIndisponivelException;
use App\Services\PowerBi\PowerBiRecusadoException;
use App\Services\PowerBi\PowerBiRefresher;
use App\Services\PowerBi\TravaDeRefreshPowerBi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pede ao Power BI que atualize o dataset depois de uma importação do TOTVS bem-sucedida.
 *
 * ⚠️ É JOB, e não uma chamada dentro do `AtualizadorTotvs`, para que uma falha do Power
 * BI NUNCA marque a importação como `falha`: os dados entraram no banco, e isso é o que
 * a rodada registra. O resultado do BI vai para um passo próprio da rodada
 * (`powerbi:refresh`), em vermelho quando dá errado, visível em `/atualizacoes`.
 *
 * Fluxo:
 * - dentro da cota → dispara e registra na trava;
 * - fora da cota → se reagenda para quando a janela abrir, a menos que já exista um
 *   refresh adiado (que vai cobrir esta importação também);
 * - job adiado que acorda depois de outro disparo já ter acontecido → não faz nada: o
 *   dado dele já foi levado.
 */
class AtualizarPowerBiJob implements ShouldQueue
{
    use Queueable;

    /** Nome do passo nos `passos` da rodada — a tela usa como chave. */
    public const PASSO = 'powerbi:refresh';

    /** Só a falha de rede/5xx chega a retentar; recusa não (dá a mesma resposta). */
    public int $tries = 3;

    public int $timeout = 90;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * @param  int|null  $agendadoEm  timestamp de quando ESTE job foi adiado pela trava;
     *                                nulo no disparo normal, logo depois da importação
     */
    public function __construct(
        public readonly int $rodadaId,
        public readonly ?int $agendadoEm = null,
    ) {
    }

    public function handle(PowerBiRefresher $refresher, TravaDeRefreshPowerBi $trava): void
    {
        if (! config('powerbi.refresh.habilitado')) {
            // Desligado entre o enfileiramento e a execução.
            $this->registrarPasso('Refresh do Power BI desligado (POWERBI_REFRESH_HABILITADO) — nada foi pedido.', false);

            return;
        }

        try {
            $trava->exclusivo(fn () => $this->decidirEDisparar($refresher, $trava));
        } catch (PowerBiRecusadoException $e) {
            Log::warning('Power BI recusou o refresh', ['rodada' => $this->rodadaId, 'erro' => $e->getMessage()]);
            $this->registrarPasso($e->getMessage(), true);
        }
        // PowerBiIndisponivelException sobe de propósito: é ela que faz a fila retentar.
    }

    public function failed(Throwable $e): void
    {
        $mensagem = $e instanceof PowerBiIndisponivelException
            ? 'O Power BI não respondeu depois de '.$this->tries.' tentativas. Os dados estão no banco; o relatório atualiza na próxima importação ou pelo refresh manual do Serviço.'
            : 'Falha ao pedir o refresh do Power BI: '.$e->getMessage();

        $this->registrarPasso($mensagem, true);
    }

    private function decidirEDisparar(PowerBiRefresher $refresher, TravaDeRefreshPowerBi $trava): void
    {
        if ($this->agendadoEm !== null) {
            $ultimo = $trava->ultimoDisparo();

            if ($ultimo !== null && $ultimo->getTimestamp() >= $this->agendadoEm) {
                $this->registrarPasso("Coberto pelo refresh das {$ultimo->format('H:i')}, que já levou estes dados.", false);

                return;
            }

            // A marca de pendência é DESTE job. Sem limpar, se a janela ainda estiver
            // fechada (relógio), ele não conseguiria se reagendar e o refresh se perderia.
            $trava->limparPendente();
        }

        $janela = $trava->proximaJanela();

        if ($janela !== null) {
            $uso = $trava->disparosNasUltimas24h().'/'.$trava->maximo();

            if ($trava->marcarPendente($janela)) {
                self::dispatch($this->rodadaId, now()->getTimestamp())->delay($janela);
                $this->registrarPasso("Adiado para {$janela->format('d/m H:i')} pela trava de cota ({$uso} refreshes nas últimas 24 h).", false);
            } else {
                $para = $trava->pendentePara()?->format('d/m H:i') ?? 'breve';
                $this->registrarPasso("Já existe um refresh agendado para {$para}; ele vai levar estes dados também ({$uso} nas últimas 24 h).", false);
            }

            return;
        }

        $requestId = $refresher->disparar();
        $trava->registrar();

        $this->registrarPasso(sprintf(
            'Refresh pedido ao Power BI às %s (%d/%d nas últimas 24 h).%s',
            now()->format('H:i'),
            $trava->disparosNasUltimas24h(),
            $trava->maximo(),
            $requestId !== '' ? " RequestId {$requestId}." : '',
        ), false);
    }

    /**
     * Escreve (ou substitui) o passo `powerbi:refresh` da rodada. A rodada já está
     * encerrada quando isto roda — o despacho acontece depois do `encerrar()` justamente
     * para este UPDATE não ser sobrescrito por ele.
     *
     * ⚠️ Nunca propaga exceção: não conseguir anotar o resultado não pode virar uma
     * retentativa que dispararia o refresh de novo.
     */
    private function registrarPasso(string $saida, bool $falhou): void
    {
        try {
            $rodada = TotvsImportacao::find($this->rodadaId);

            if ($rodada === null) {
                return;
            }

            $passos = array_values(array_filter(
                $rodada->passos ?? [],
                fn ($p) => ($p['comando'] ?? null) !== self::PASSO,
            ));

            $passos[] = ['comando' => self::PASSO, 'segundos' => 0, 'falhou' => $falhou, 'saida' => $saida];

            $rodada->update(['passos' => $passos]);
        } catch (Throwable $e) {
            Log::error('Não foi possível registrar o resultado do refresh do Power BI', [
                'rodada' => $this->rodadaId,
                'resultado' => $saida,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
