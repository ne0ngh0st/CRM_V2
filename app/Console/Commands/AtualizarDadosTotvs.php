<?php

namespace App\Console\Commands;

use App\Services\Totvs\AtualizadorTotvs;
use Illuminate\Console\Command;

/**
 * Gatilho de linha de comando para `AtualizadorTotvs` — é o que o cron horário chama
 * (ver `routes/console.php`).
 *
 * ⚠️ A LÓGICA NÃO MORA AQUI, de propósito. O outro gatilho é o botão da tela
 * (`AtualizarDadosTotvsJob`), e a ordem dos imports mais a regra de "só importa se mudou"
 * têm que ser as mesmas nos dois — ver o docblock do serviço.
 */
class AtualizarDadosTotvs extends Command
{
    protected $signature = 'totvs:atualizar
        {--forcar : importa mesmo que nenhum relatório tenha mudado}
        {--sem-sync : não baixa do S3, usa o que já está em disco}';

    protected $description = 'Sincroniza os relatórios do TOTVS do S3 e importa o que mudou';

    public function handle(AtualizadorTotvs $atualizador): int
    {
        $rodada = $atualizador->executar(
            origem: 'agendador',
            forcar: (bool) $this->option('forcar'),
            sincronizar: ! $this->option('sem-sync'),
        );

        foreach ($rodada->passos ?? [] as $passo) {
            $this->newLine();
            $this->info("── {$passo['comando']}  ({$passo['segundos']}s)");
            $this->line($passo['saida']);
        }

        $this->newLine();

        return match ($rodada->status) {
            'sucesso' => $this->concluir('Importação concluída.'),
            'sem_mudanca' => $this->concluir('Nenhum relatório mudou desde a última importação. Nada a fazer. (--forcar importa mesmo assim.)'),
            default => $this->falhar((string) $rodada->erro),
        };
    }

    private function concluir(string $mensagem): int
    {
        $this->info($mensagem);

        return self::SUCCESS;
    }

    private function falhar(string $mensagem): int
    {
        $this->error($mensagem !== '' ? $mensagem : 'Falhou.');

        return self::FAILURE;
    }
}
