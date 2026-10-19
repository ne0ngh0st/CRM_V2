<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Painel ao vivo das duas cargas que a planilha do supermercadista espera:
 * a busca de faturamento (grava linha a linha) e a contagem de lojas da Receita
 * (só grava no fim da varredura; enquanto isso, mostra o zip que está aberto).
 */
class AcompanharPesquisaMercado extends Command
{
    protected $signature = 'pesquisa:acompanhar {--segundos=3 : intervalo entre as leituras}';

    protected $description = 'Acompanha ao vivo a busca de faturamento e a contagem de lojas da Receita';

    public function handle(): int
    {
        set_time_limit(0);
        $intervalo = max(1, (int) $this->option('segundos'));

        $this->line('Atualiza a cada '.$intervalo.'s. Ctrl+C para sair. A planilha só enxerga o que já foi gravado.');

        while (! $this->output->isQuiet()) {
            $web = DB::table('faturamento_mercado')
                ->where('fonte', 'web')
                ->selectRaw('count(*) as consultas, sum(valor is not null) as com_numero')
                ->first();
            $lojas = (int) DB::table('cnpj_situacoes')->whereNotNull('lojas_receita')->count();

            $this->line(sprintf(
                '%s  web %s consultas, %s com número (%s)  |  lojas %s (%s)  |  %s',
                now()->timezone('America/Sao_Paulo')->format('H:i:s'),
                number_format((int) $web->consultas, 0, ',', '.'),
                number_format((int) $web->com_numero, 0, ',', '.'),
                $this->rodando('pesquisa:faturamento-mercado') ? 'buscando' : 'parada',
                $lojas === 0 ? 'ainda não gravadas' : number_format($lojas, 0, ',', '.').' CNPJs',
                $this->rodando('receita:importar-situacoes') ? 'varrendo' : 'parada',
                $this->arquivoAtual(),
            ));

            sleep($intervalo);
        }

        return self::SUCCESS;
    }

    private function rodando(string $comando): bool
    {
        $lista = (string) shell_exec('ps aux 2>/dev/null');

        return str_contains($lista, $comando);
    }

    private function arquivoAtual(): string
    {
        $zips = glob(config('receita.base.diretorio').'/*.zip') ?: [];
        if ($zips === []) {
            return 'nenhum zip aberto';
        }

        $caminho = $zips[0];

        return basename($caminho).' ('.number_format(filesize($caminho) / 1048576, 0, ',', '.').' MB)';
    }
}
