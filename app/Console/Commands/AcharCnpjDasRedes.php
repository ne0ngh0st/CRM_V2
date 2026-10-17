<?php

namespace App\Console\Commands;

use App\Models\RedeMercado;
use App\Services\Receita\BaseAberta;
use App\Services\VisaoDiretor\Redes\CnpjDasRedes;
use App\Services\VisaoDiretor\Redes\MaioresRedes;
use Illuminate\Console\Command;

/**
 * Acha o CNPJ de cada rede do mercado (Maiores redes) na base aberta da Receita e grava a
 * situação da matriz — é o que diz na tela se a rede está baixada ou inapta. A regra de
 * escolha mora no `CnpjDasRedes`; aqui só os arquivos e a gravação.
 *
 * Lê cada zip DUAS vezes (empresas → estabelecimentos → empresas → estabelecimentos), então
 * baixa os 20 de uma vez e só apaga no fim: ~6,5 GB de disco durante a carga. Medido em dev
 * (2026-10-17, base 2026-10, arquivos já no disco): 20,6 min, um processo só — etapa 1
 * 690 s, 2 386 s, 3 53 s, 4 103 s. Em produção soma o download (~6,5 GB).
 *
 * Roda depois do `receita:importar-situacoes` no agendamento semanal e não faz nada se o
 * mês mais recente já foi processado. Rede com CNPJ informado à mão não é tocada.
 */
class AcharCnpjDasRedes extends Command
{
    protected $signature = 'visao-diretor:cnpj-das-redes
        {--referencia= : mês da base (AAAA-MM); padrão = o mais recente publicado}
        {--empresas=* : zips de Empresas já baixados (pula o download; teste e reprocessamento)}
        {--estabelecimentos=* : zips de Estabelecimentos já baixados (junto de --empresas)}
        {--forcar : refaz mesmo se este mês já foi processado}
        {--dry-run : procura e mostra, sem gravar}';

    protected $description = 'Acha o CNPJ das redes do mercado na base aberta da Receita';

    public function handle(BaseAberta $base, MaioresRedes $maioresRedes): int
    {
        // Contagem por rede e raiz das ~550 mil lojas candidatas: ~300 MB no pior caso.
        ini_set('memory_limit', '2048M');

        $empresas = (array) $this->option('empresas');
        $estabelecimentos = (array) $this->option('estabelecimentos');
        $locais = $empresas !== [] || $estabelecimentos !== [];

        if ($locais && ($empresas === [] || $estabelecimentos === [])) {
            $this->error('--empresas e --estabelecimentos vão juntos.');

            return self::FAILURE;
        }

        $referencia = $this->option('referencia') ?: ($locais ? now()->format('Y-m') : $base->referenciaMaisRecente());

        if (! $this->option('forcar') && ! $locais && RedeMercado::where('cnpj_referencia', $referencia)->exists()) {
            $this->info("Base {$referencia} já processada. Nada a fazer (--forcar refaz).");

            return self::SUCCESS;
        }

        $redes = RedeMercado::where('ativa', true)->get(['id', 'nome', 'apelidos', 'categoria', 'lojas']);
        $busca = app()->make(CnpjDasRedes::class);
        $busca->preparar($redes->map(fn (RedeMercado $r) => $r->only(['id', 'nome', 'apelidos', 'categoria', 'lojas']))->all());
        $this->line("Redes: {$redes->count()} · base {$referencia}");

        $baixados = [];

        try {
            if (! $locais) {
                foreach (range(0, (int) config('receita.base.arquivos') - 1) as $i) {
                    $empresas[] = $baixados[] = $base->baixar($referencia, $i, 'Empresas');
                    $estabelecimentos[] = $baixados[] = $base->baixar($referencia, $i, 'Estabelecimentos');
                }
            }

            $this->etapa('1/4 razão social (Empresas)', $empresas, fn ($c) => $busca->lerEmpresas($base->linhas($c)));
            $this->etapa('2/4 lojas (Estabelecimentos)', $estabelecimentos, fn ($c) => $busca->lerEstabelecimentos($base->linhas($c)));
            $busca->escolherFinalistas();
            $this->etapa('3/4 empresas finalistas', $empresas, fn ($c) => $busca->lerEmpresasDasFinalistas($base->linhas($c)));
            $this->etapa('4/4 matrizes', $estabelecimentos, fn ($c) => $busca->lerMatrizes($base->linhas($c)));
        } finally {
            foreach ($baixados as $caminho) {
                @unlink($caminho);
            }
        }

        $resultado = $busca->resultado();
        $this->resumo($resultado, $redes->keyBy('id'));

        if ($this->option('dry-run')) {
            $this->info('[dry-run] nada foi gravado.');

            return self::SUCCESS;
        }

        $gravadas = $maioresRedes->gravarCnpjs($resultado, $referencia);
        $this->info('Redes gravadas: '.array_sum($gravadas).' (as informadas à mão ficaram como estavam).');

        return self::SUCCESS;
    }

    /** @param  list<string>  $arquivos */
    private function etapa(string $nome, array $arquivos, callable $ler): void
    {
        $inicio = microtime(true);
        $linhas = 0;
        foreach ($arquivos as $caminho) {
            $linhas += $ler($caminho);
        }

        $this->line(sprintf('  %s: %s linhas (%.0f s)', $nome, number_format($linhas, 0, ',', '.'), microtime(true) - $inicio));
    }

    private function resumo(array $resultado, $redes): void
    {
        $contagem = array_count_values(array_column($resultado, 'confianca'));
        foreach (CnpjDasRedes::CONFIANCAS as $chave => $rotulo) {
            if (isset($contagem[$chave])) {
                $this->line(sprintf('  %-28s %d', $rotulo, $contagem[$chave]));
            }
        }

        $fechadas = array_filter($resultado, fn ($r) => $r['matriz'] !== null && $r['matriz']['situacao'] !== 'ATIVA');
        if ($fechadas !== []) {
            $this->warn('Redes com a matriz fora da situação ATIVA:');
            foreach ($fechadas as $id => $r) {
                $this->line("  {$redes[$id]->nome} → {$r['razao_social']} ({$r['cnpj']}) {$r['matriz']['situacao']}");
            }
        }
    }
}
