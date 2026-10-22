<?php

namespace App\Console\Commands;

use App\Models\CotacaoMemoria;
use App\Services\Cotacao\CotacaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula a chave (`texto_normalizado`) de toda a memória da Cotação.
 *
 * ⚠️ OBRIGATÓRIO depois de mexer em `motor/cotacao/dicionarios/sinonimos.json` ou no
 * `normalizar.py`: o texto normalizado é a chave da memória, e mudar a normalização
 * faria as decisões antigas pararem de casar — o item voltaria para a fila de
 * conferência sem erro nenhum, e ninguém ligaria uma coisa à outra.
 *
 * Duas descrições que passam a dar a mesma chave: fica a decisão mais recente.
 */
class RenormalizarMemoriaCotacao extends Command
{
    protected $signature = 'cotacao:renormalizar-memoria {--dry-run : só conta}';

    protected $description = 'Recalcula as chaves da memória da Cotação pelo motor atual';

    public function handle(CotacaoService $servico): int
    {
        $memoria = CotacaoMemoria::query()->orderBy('atualizado_em')->get()->values();

        if ($memoria->isEmpty()) {
            $this->info('Memória vazia.');

            return self::SUCCESS;
        }

        $chaves = $servico->normalizar($memoria->pluck('descricao_cliente')->all());
        $mudaram = $memoria->filter(fn ($m, $i) => $m->texto_normalizado !== $chaves[$i])->count();

        $this->line("{$mudaram} de {$memoria->count()} chaves mudam.");

        if ($mudaram === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($memoria, $chaves) {
            $linhas = [];
            // Do mais antigo para o mais novo: em colisão, o mais novo sobrescreve.
            foreach ($memoria as $i => $m) {
                $linhas[$chaves[$i]] = ['texto_normalizado' => $chaves[$i]] + $m->only([
                    'descricao_cliente', 'decisao', 'codigo', 'descricao_nossa', 'user_id', 'quem', 'decisao_id',
                ]) + ['atualizado_em' => $m->atualizado_em];
            }

            CotacaoMemoria::query()->delete();
            foreach (array_chunk(array_values($linhas), 500) as $lote) {
                CotacaoMemoria::insert($lote);
            }
        });

        $this->info('Memória renormalizada: '.count(array_unique($chaves)).' chaves.');

        return self::SUCCESS;
    }
}
