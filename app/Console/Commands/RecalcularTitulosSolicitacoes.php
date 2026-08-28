<?php

namespace App\Console\Commands;

use App\Models\SolicitacaoBobina;
use App\Models\SolicitacaoEtiqueta;
use App\Services\Cadastros\SolicitacaoTituloResolver;
use Illuminate\Console\Command;

/**
 * Regrava `titulo_padronizado` das solicitações já existentes.
 *
 * O v2 calcula o título uma vez, no store (o legado recalculava a cada leitura),
 * então correção de regra não alcança registro antigo sozinha — daí este comando.
 * Rodar depois de qualquer mudança no SolicitacaoTituloResolver.
 */
class RecalcularTitulosSolicitacoes extends Command
{
    protected $signature = 'cadastros:recalcular-titulos {--dry-run : Só mostra o que mudaria, sem gravar}';

    protected $description = 'Recalcula o título TOTVS das solicitações de bobina e etiqueta já cadastradas';

    public function handle(SolicitacaoTituloResolver $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo dry-run: nada será gravado.');
        }

        $bobinas = $this->processar(
            'bobinas',
            SolicitacaoBobina::query(),
            fn (SolicitacaoBobina $s) => $resolver->bobina(
                (string) $s->nomenclatura,
                $s->papel,
                $s->largura,
                $s->metragem,
                $s->gramatura,
            ),
            $dryRun,
        );

        $etiquetas = $this->processar(
            'etiquetas',
            SolicitacaoEtiqueta::query(),
            fn (SolicitacaoEtiqueta $s) => $resolver->etiqueta(
                (string) $s->nomenclatura,
                $s->medidas,
                $s->tipo_adesivo,
                $s->metragem,
            ),
            $dryRun,
        );

        $this->newLine();
        $this->info(sprintf(
            '%s: %d bobina(s) e %d etiqueta(s) com título diferente.',
            $dryRun ? 'Seriam atualizadas' : 'Atualizadas',
            $bobinas,
            $etiquetas,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(\Illuminate\Database\Eloquent\Model): string  $gerar
     */
    private function processar(string $rotulo, $query, callable $gerar, bool $dryRun): int
    {
        $alterados = 0;

        $query->chunkById(500, function ($registros) use ($gerar, $dryRun, &$alterados) {
            foreach ($registros as $registro) {
                $novo = $gerar($registro);

                if ($novo === $registro->titulo_padronizado) {
                    continue;
                }

                $this->line(sprintf(
                    '  #%d
    de : %s
    pra: %s',
                    $registro->id,
                    $registro->titulo_padronizado ?? '(vazio)',
                    $novo,
                ));

                $alterados++;

                if (! $dryRun) {
                    $registro->forceFill(['titulo_padronizado' => $novo])->save();
                }
            }
        });

        $this->line(sprintf('%s: %d registro(s) divergente(s).', ucfirst($rotulo), $alterados));

        return $alterados;
    }
}
