<?php

namespace App\Console\Commands;

use App\Services\Carteira\UltimoContatoSincronizador;
use Illuminate\Console\Command;

/**
 * Reconstrói `clientes.data_ultimo_contato` / `canal_ultimo_contato` a partir de
 * `ligacoes`.
 *
 * As colunas são desnormalizadas e mantidas pelo hook `Ligacao::created()`. Isso cobre
 * o uso normal, mas não cobre escrita fora do fluxo: um `DELETE` direto em `ligacoes`,
 * uma carga em massa, ou um bug em que o hook não rodou.
 *
 * ⚠️ É seguro rodar a qualquer momento — o valor é DERIVADO, então a reconstrução é
 * idempotente: rodar duas vezes dá o mesmo resultado, e rodar num banco correto não
 * muda nada. Não é comando destrutivo apesar do UPDATE em massa, porque não existe
 * informação nessas colunas que não esteja em `ligacoes`.
 */
class RecalcularUltimoContato extends Command
{
    protected $signature = 'carteira:recalcular-ultimo-contato';

    protected $description = 'Reconstrói a data e o canal do último contato de cada cliente a partir de ligacoes';

    public function handle(UltimoContatoSincronizador $sincronizador): int
    {
        $inicio = microtime(true);
        $afetados = $sincronizador->reconstruirTudo();
        $ms = (int) ((microtime(true) - $inicio) * 1000);

        $this->info("Último contato reconstruído: {$afetados} clientes atualizados em {$ms} ms.");

        return self::SUCCESS;
    }
}
