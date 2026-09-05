<?php

namespace Database\Seeders;

use App\Models\PotencialPeso;
use App\Models\Segmento;
use App\Services\Potencial\FamiliaProduto;
use Illuminate\Database\Seeder;

/**
 * Garante uma linha de peso para cada par (segmento, família), com peso 1,00.
 *
 * ⚠️ Cria SÓ o que falta — nunca sobrescreve peso já gravado. É a lição do `FacaSeeder`,
 * que apagava o cadastro feito à mão do admin a cada `db:seed`: aqui, quem edita é a
 * direção, e `db:seed` roda inteiro em toda restauração de banco.
 *
 * ⚠️ Também serve de rede para segmento novo: `SegmentoSeeder` pode ganhar linhas, e sem
 * peso o segmento sairia dos candidatos de todas as famílias — o cliente dele
 * desapareceria do Potencial em silêncio, em vez de contar com peso neutro.
 */
class PotencialPesoSeeder extends Seeder
{
    public function run(): void
    {
        $segmentos = Segmento::query()->pluck('id');

        if ($segmentos->isEmpty()) {
            $this->command?->warn('Nenhum segmento cadastrado — rode o SegmentoSeeder antes.');

            return;
        }

        $existentes = PotencialPeso::query()
            ->get(['segmento_id', 'familia'])
            ->map(fn ($p) => $p->segmento_id.'|'.$p->familia)
            ->flip();

        $novas = [];
        $agora = now();

        foreach ($segmentos as $segmentoId) {
            foreach (FamiliaProduto::chaves() as $familia) {
                if ($existentes->has($segmentoId.'|'.$familia)) {
                    continue;
                }

                $novas[] = [
                    'segmento_id' => $segmentoId,
                    'familia' => $familia,
                    'peso' => 1.00,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        if ($novas !== []) {
            PotencialPeso::query()->insert($novas);
        }

        $this->command?->info(sprintf(
            'Pesos de potencial: %d criados, %d preservados.',
            count($novas),
            $existentes->count(),
        ));
    }
}
