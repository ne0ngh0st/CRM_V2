<?php

namespace App\Console\Commands;

use App\Jobs\AquecerCacheDashboardJob;
use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Publica no CloudWatch os números que só a aplicação conhece.
 *
 * POR QUE ISTO EXISTE — incidente de 2026-08-29:
 *
 * O security group não liberava a porta 8080 entre os app nodes, então todo broadcast do
 * Reverb pendurava 10s até dar timeout. Com 2.792 notificações criadas às 07:00, a fila
 * ficou ~7,7 horas ocupada e o AquecerCacheDashboardJob não rodou por 6 horas.
 *
 * Durante essas 6 horas, TODOS os alarmes nativos da AWS estavam verdes: CPU em 0,4%,
 * memória sobrando, ALB saudável, zero 5xx. A infraestrutura estava perfeita; o que
 * estava quebrado era um número que a AWS não tem como enxergar.
 *
 * Daí estas três métricas. A primeira teria disparado às 07:10.
 *
 * ⚠️ Nunca deixe uma falha daqui derrubar o scheduler. Se o CloudWatch estiver fora,
 * queremos perder a métrica, não a execução das outras tarefas agendadas — por isso todo
 * o envio vive dentro de try/catch.
 */
class PublicarMetricas extends Command
{
    protected $signature = 'metricas:publicar {--mostrar : Só imprime os valores, sem enviar}';

    protected $description = 'Publica profundidade da fila e idade do cache no CloudWatch';

    /** O namespace é o mesmo travado na condição da política IAM (crm-v2-monitoramento). */
    private const NAMESPACE_CW = 'CRM-V2';

    public function handle(): int
    {
        $metricas = $this->coletar();

        foreach ($metricas as $nome => $valor) {
            $this->line(sprintf('  %-26s %s', $nome, $valor));
        }

        if ($this->option('mostrar')) {
            return self::SUCCESS;
        }

        try {
            $this->enviar($metricas);
            $this->info('  publicado em '.self::NAMESPACE_CW);
        } catch (\Throwable $e) {
            // Log, não exceção: o scheduler roda a cada minuto e uma indisponibilidade do
            // CloudWatch não pode virar 1.440 stack traces por dia nem parar o resto.
            Log::warning('Falha ao publicar métricas no CloudWatch: '.$e->getMessage());
            $this->warn('  falhou: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /** @return array<string, int|float> */
    private function coletar(): array
    {
        /*
         * `Queue::size()` e não um LLEN direto no Redis: o driver soma pendentes,
         * atrasados e reservados, e resolve o prefixo sozinho. Um LLEN escrito à mão
         * quebraria silenciosamente se o prefixo mudasse — e mostraria zero, que é
         * justamente o valor que faz parecer que está tudo bem.
         */
        $fila = Queue::size();

        $ultimo = Cache::get(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);
        $idadeMinutos = $ultimo && isset($ultimo['em'])
            ? now()->diffInMinutes(\Illuminate\Support\Carbon::parse($ultimo['em']), absolute: true)
            // Sem registro nenhum é pior que registro velho: devolve um valor alto de
            // propósito, para o alarme disparar em vez de ficar sem dado (dado ausente
            // não dispara nada, e o silêncio pareceria normalidade).
            : 999;

        return [
            'FilaProfundidade' => $fila,
            'AquecimentoIdadeMinutos' => $idadeMinutos,
            'JobsFalhados' => DB::table('failed_jobs')->count(),
        ];
    }

    /** @param array<string, int|float> $metricas */
    private function enviar(array $metricas): void
    {
        // Credencial vem da IAM role da instância (metadata service) — sem chave no .env.
        $cw = new CloudWatchClient([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region', 'sa-east-1'),
        ]);

        $dados = [];
        foreach ($metricas as $nome => $valor) {
            $dados[] = [
                'MetricName' => $nome,
                'Value' => (float) $valor,
                'Unit' => $nome === 'AquecimentoIdadeMinutos' ? 'Count' : 'Count',
                'Timestamp' => now(),
            ];
        }

        $cw->putMetricData([
            'Namespace' => self::NAMESPACE_CW,
            'MetricData' => $dados,
        ]);
    }
}
