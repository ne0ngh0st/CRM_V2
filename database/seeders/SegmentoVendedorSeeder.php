<?php

namespace Database\Seeders;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\VendedorPerfil;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * "Segmento" aqui é o setor/vertical do cliente (setor real do TOTVS, ver
 * SegmentoSeeder) — cada vendedor atende 0-2 setores (nem todo vendedor tem
 * segmento definido — vendedor recém-chegado ou generalista, por exemplo).
 * Não confundir com produto (bobina/etiqueta/...), que é outra coisa (ver
 * FaturamentoSeeder).
 *
 * **Regra de negócio confirmada por Tony (2026-07-29): todo representante
 * (perfil `representante`) atende só SUPERMERCADISTA** — sem variação, sem
 * chance de zero. Vendedor interno (perfil `vendedor`) continua com a
 * distribuição plausível de 0-2 segmentos (demonstração/dev).
 *
 * Atribuição vendedor→segmento é decisão de negócio (não vem de import TOTVS,
 * é corrigida manualmente pela tela Equipe).
 */
class SegmentoVendedorSeeder extends Seeder
{
    private const CURVAS = ['A', 'B', 'C'];

    public function run(): void
    {
        $supermercadistaId = Segmento::where('nome', 'SUPERMERCADISTA')->value('id');

        if (! $supermercadistaId) {
            return;
        }

        $pool = $this->poolPonderado();

        VendedorPerfil::query()
            ->with('user')
            ->get()
            ->filter(fn (VendedorPerfil $vp) => $vp->user?->hasAnyRole(['vendedor', 'representante']))
            ->groupBy('cod_vendedor')
            ->each(function (Collection $grupo, string $codVendedor) use ($pool, $supermercadistaId) {
                if ($grupo->first()->user->hasRole('representante')) {
                    SegmentoVendedor::create([
                        'cod_vendedor' => $codVendedor,
                        'segmento_id' => $supermercadistaId,
                    ]);

                    return;
                }

                if ($pool->isEmpty()) {
                    return;
                }

                $quantidade = match (true) {
                    fake()->boolean(10) => 0,
                    fake()->boolean(15) => 2,
                    default => 1,
                };

                if ($quantidade === 0) {
                    return;
                }

                $segmentoIds = $pool->random(min($quantidade, $pool->count()));

                foreach ($segmentoIds->unique() as $segmentoId) {
                    SegmentoVendedor::create([
                        'cod_vendedor' => $codVendedor,
                        'segmento_id' => $segmentoId,
                        'curva_abc' => fake()->randomElement(self::CURVAS),
                    ]);
                }
            });
    }

    /** @return Collection<int, int> ids de Segmento, com SUPERMERCADISTA repetido pra pesar a amostragem (vendedores internos). */
    private function poolPonderado(): Collection
    {
        $idsPorNome = Segmento::pluck('id', 'nome');

        if ($idsPorNome->isEmpty()) {
            return collect();
        }

        $supermercadista = $idsPorNome->get('SUPERMERCADISTA');
        $outros = $idsPorNome->except(['SUPERMERCADISTA'])->values();

        return collect(array_fill(0, 10, $supermercadista))
            ->filter()
            ->merge($outros);
    }
}
