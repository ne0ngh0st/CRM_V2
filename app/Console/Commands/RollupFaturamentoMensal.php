<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\FaturamentoMensalRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recalcula `faturamento_cliente_mensal` (a base de faturamento da Visão Diretor).
 *
 * Sem opção: mês corrente e anterior — é o que o `totvs:atualizar` roda depois de cada
 * importação bem-sucedida. Com `--desde`/`--ate`: os meses pedidos, para a carga histórica
 * (uma vez, à mão) ou depois de recarregar um ano de `faturamentos` por outro caminho.
 */
class RollupFaturamentoMensal extends Command
{
    protected $signature = 'faturamento:rollup-mensal
        {--desde= : primeiro mês (AAAA-MM)}
        {--ate= : último mês (AAAA-MM); padrão = mês corrente}';

    protected $description = 'Recalcula o faturamento por cliente e mês usado pela Visão Diretor';

    public function handle(FaturamentoMensalRollup $rollup): int
    {
        $desde = $this->option('desde');
        $ate = $this->option('ate');

        try {
            $inicio = microtime(true);

            if ($desde === null && $ate === null) {
                $meses = $rollup->recalcularRecentes();
            } else {
                $meses = $rollup->recalcular(
                    $this->mes($desde ?? $ate),
                    $ate !== null ? $this->mes($ate) : CarbonImmutable::today(),
                );
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d mês(es) recalculado(s) em %.1f s.', $meses, microtime(true) - $inicio));

        return self::SUCCESS;
    }

    private function mes(string $valor): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $valor)) {
            throw new \InvalidArgumentException("Mês inválido: \"{$valor}\". Use AAAA-MM.");
        }

        return CarbonImmutable::createFromFormat('Y-m-d', "{$valor}-01")->startOfDay();
    }
}
