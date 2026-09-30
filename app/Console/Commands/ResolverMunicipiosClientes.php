<?php

namespace App\Console\Commands;

use App\Services\Geografia\MunicipioSincronizador;
use App\Services\PowerBi\SchemaBi;
use Illuminate\Console\Command;

/**
 * Preenche `clientes.cod_municipio` a partir do de-para de município do schema do BI.
 *
 * Os imports de cliente já fazem isto no fim de cada rodada; o comando existe para a
 * primeira carga, para depois de acrescentar linha ao `de_para_municipio`, e para ver a
 * cobertura. Seguro rodar sempre: o valor é derivado, então é idempotente.
 */
class ResolverMunicipiosClientes extends Command
{
    protected $signature = 'clientes:resolver-municipios
        {--dry-run : só mostra a cobertura atual e o que não resolve, sem escrever}';

    protected $description = 'Resolve o código IBGE do município de cada cliente (clientes.cod_municipio)';

    public function handle(MunicipioSincronizador $sincronizador): int
    {
        if (! SchemaBi::existe()) {
            $this->error("O schema '".SchemaBi::nome()."' não existe neste MySQL — sem o de-para de município não há o que resolver.");

            return self::FAILURE;
        }

        if (! $this->option('dry-run')) {
            $alteradas = $sincronizador->sincronizar();
            $this->info('Filiais com o município alterado: '.number_format($alteradas, 0, ',', '.'));
        }

        $cobertura = $sincronizador->cobertura();
        $faltam = $cobertura['total'] - $cobertura['resolvidos'];

        $this->line(sprintf(
            'Cobertura: %s de %s filiais (%s%%). Sem localização: %s.',
            number_format($cobertura['resolvidos'], 0, ',', '.'),
            number_format($cobertura['total'], 0, ',', '.'),
            $cobertura['total'] > 0 ? number_format($cobertura['resolvidos'] / $cobertura['total'] * 100, 2, ',', '.') : '0',
            number_format($faltam, 0, ',', '.'),
        ));

        if ($faltam > 0) {
            $this->table(
                ['UF', 'Município (como veio do TOTVS)', 'Filiais'],
                array_map(
                    fn ($l) => [$l->estado ?? '—', $l->municipio ?? '—', $l->filiais],
                    $sincronizador->naoResolvidos(),
                ),
            );
        }

        return self::SUCCESS;
    }
}
