<?php

namespace App\Console\Commands;

use App\Services\Totvs\LeitorRelatorio;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mostra o que o v2 enxerga num relatório do TOTVS: título, colunas, contagem e a
 * faixa de datas. Não escreve nada em lugar nenhum.
 *
 * Serve para duas coisas: conferir que a pasta está montada e o arquivo é o esperado
 * ANTES de rodar um import, e descobrir o nome real das colunas de um relatório novo
 * sem abrir um CSV de 98 MB no Excel.
 */
class InspecionarRelatorioTotvs extends Command
{
    protected $signature = 'totvs:inspecionar
        {dominio? : chave em config/totvs.arquivos (ex.: clientes). Sem isso, inspeciona todos}
        {--linhas=0 : quantas linhas de exemplo mostrar}';

    protected $description = 'Mostra título, colunas e cobertura de um relatório do TOTVS (só leitura)';

    public function handle(): int
    {
        $configurados = config('totvs.arquivos', []);
        $dominio = $this->argument('dominio');

        if ($dominio !== null && ! isset($configurados[$dominio])) {
            $this->error("Domínio desconhecido: {$dominio}");
            $this->line('Disponíveis: '.implode(', ', array_keys($configurados)));

            return self::FAILURE;
        }

        $alvos = $dominio !== null ? [$dominio => $configurados[$dominio]] : $configurados;
        $falhou = false;

        foreach ($alvos as $chave => $cfg) {
            $this->newLine();
            $this->line("<fg=cyan>── {$chave}</>");

            try {
                $this->inspecionar($cfg);
            } catch (RuntimeException $e) {
                $falhou = true;
                $this->error('   '.$e->getMessage());
            }
        }

        $this->newLine();

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    private function inspecionar(array $cfg): void
    {
        $caminho = rtrim((string) config('totvs.diretorio'), '/').'/'.$cfg['arquivo'];
        $leitor = LeitorRelatorio::abrir($caminho);

        $this->line('   arquivo : '.$cfg['arquivo'].'  ('.$this->tamanho($caminho).')');
        $this->line('   título  : '.($leitor->titulo() ?? '(sem linha de título)'));
        $this->line('   período : '.$cfg['periodo']);
        $this->line('   colunas : '.count($leitor->cabecalho()));
        $this->line('             '.implode(', ', $leitor->cabecalho()));

        if (($cfg['rlt'] ?? null) !== null && ! str_contains((string) $leitor->titulo(), $cfg['rlt'])) {
            $this->warn('   ⚠️ o título não bate com o RLT esperado ('.$cfg['rlt'].') — arquivo trocado?');
        }

        $colunaData = $cfg['coluna_data'] ?? null;
        $total = 0;
        $datas = [];
        $exemplos = (int) $this->option('linhas');
        $mostradas = 0;

        foreach ($leitor->linhas() as $linha) {
            $total++;

            if ($colunaData !== null && ($linha[$colunaData] ?? '') !== '') {
                $datas[] = substr($linha[$colunaData], 0, 10);
            }

            if ($mostradas < $exemplos) {
                $mostradas++;
                $this->line('   ex.'.$mostradas.'   : '.mb_substr(json_encode($linha, JSON_UNESCAPED_UNICODE), 0, 200));
            }
        }

        $this->line('   linhas  : '.number_format($total, 0, ',', '.'));

        if ($datas !== []) {
            usort($datas, fn ($a, $b) => $this->paraOrdenacao($a) <=> $this->paraOrdenacao($b));
            $this->line('   datas   : '.reset($datas).' até '.end($datas).'  (coluna '.$colunaData.')');
        }
    }

    private function paraOrdenacao(string $data): string
    {
        // O TOTVS exporta dd/mm/aaaa; comparar como texto nesse formato ordena por dia.
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m) ? $m[3].$m[2].$m[1] : $data;
    }

    private function tamanho(string $caminho): string
    {
        $bytes = filesize($caminho) ?: 0;

        return $bytes > 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
