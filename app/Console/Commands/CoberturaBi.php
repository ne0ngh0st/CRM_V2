<?php

namespace App\Console\Commands;

use App\Services\PowerBi\SchemaBi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quanto do faturamento o Power BI consegue pôr no mapa e no corte por família, ano a ano.
 *
 * Rodar depois de cada etapa da carga histórica (ver docs/power-bi.md). Um ano com
 * município vazio ainda não foi reimportado com o conversor novo; um ano com município
 * preenchido e código IBGE baixo tem grafias que o de-para não cobre — a lista do fim
 * mostra quais.
 *
 * O plano da migração pede ≥ 99% de município resolvido antes de seguir. O legado media
 * 99,82% no faturamento.
 *
 * Só lê. Varre a view de faturamento inteira: rodar fora do horário de uso em produção.
 */
class CoberturaBi extends Command
{
    protected $signature = 'bi:cobertura {--ano= : só este ano}';

    protected $description = 'Mostra, por ano, quanto do faturamento tem município e família resolvidos para o Power BI';

    public function handle(): int
    {
        $fato = SchemaBi::tabela('vw_bi_fato_faturamento');
        $ano = $this->option('ano');
        $filtro = $ano !== null ? 'WHERE data_emissao BETWEEN ? AND ?' : '';
        $params = $ano !== null ? ["{$ano}-01-01", "{$ano}-12-31"] : [];

        $linhas = DB::select("
            SELECT
                YEAR(data_emissao) AS ano,
                COUNT(*) AS linhas,
                SUM(municipio_origem IS NOT NULL) AS com_municipio,
                SUM(cod_municipio IS NOT NULL) AS resolvidas,
                SUM(desc_familia IS NOT NULL) AS com_familia
            FROM {$fato}
            {$filtro}
            GROUP BY YEAR(data_emissao)
            ORDER BY ano
        ", $params);

        $pct = fn (int $parte, int $total) => $total === 0 ? '-' : number_format(100 * $parte / $total, 2, ',', '.').'%';

        $this->table(
            ['Ano', 'Linhas', 'Com município', 'Código IBGE resolvido', 'Com família'],
            array_map(fn ($l) => [
                $l->ano,
                number_format($l->linhas, 0, ',', '.'),
                $pct((int) $l->com_municipio, (int) $l->linhas),
                $pct((int) $l->resolvidas, (int) $l->com_municipio),
                $pct((int) $l->com_familia, (int) $l->linhas),
            ], $linhas)
        );

        $this->line('"Código IBGE resolvido" é sobre as linhas que TÊM município.');

        $pendentes = DB::select("
            SELECT municipio_origem, COUNT(*) AS linhas
            FROM {$fato}
            WHERE municipio_origem IS NOT NULL AND cod_municipio IS NULL
            ".($ano !== null ? 'AND data_emissao BETWEEN ? AND ?' : '')."
            GROUP BY municipio_origem
            ORDER BY linhas DESC
            LIMIT 15
        ", $params);

        if ($pendentes !== []) {
            $this->newLine();
            $this->warn('Municípios sem código (corrigir no cadastro do TOTVS ou, se for grafia antiga, no de-para):');

            foreach ($pendentes as $p) {
                $this->line(sprintf('  %-40s %s', $p->municipio_origem, number_format($p->linhas, 0, ',', '.')));
            }
        }

        return self::SUCCESS;
    }
}
