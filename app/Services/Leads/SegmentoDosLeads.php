<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Segmento;
use Illuminate\Support\Facades\DB;

/**
 * Segmento dos leads das bases importadas, a partir do CNAE principal na Receita
 * (`cnpj_situacoes.cnae_principal`, gravado pela carga mensal e pelo cartão CNPJ).
 *
 * Existe porque a base antiga (`origem = sistema`, o `base_marco`) chegou SEM segmento, e
 * nem o vendedor dono do lead nem o nome da empresa servem para classificar: medido em
 * produção em 08/10/2026, dois vendedores "supermercadistas" tinham 3,7 mil leads de
 * transportadora e calçados, e "MERCADO" no nome pega MERCADORIAS e deixa "Compre Mais"
 * de fora.
 *
 * ⚠️ Só PREENCHE: lead que já tem segmento não é tocado. O segmento da prospecção vem da
 * planilha de quem a montou, e o de manual/site é decisão de quem cadastrou — por isso
 * também só as bases importadas (`Lead::ORIGENS_IMPORTADAS`).
 *
 * ⚠️ CNAE fora do mapa deixa o lead SEM segmento, nunca num segmento "outros": o mapa
 * cresce quando alguém decidir o que mais é de qual segmento, e até lá a ausência é a
 * resposta honesta.
 */
class SegmentoDosLeads
{
    /**
     * CNAE principal (7 dígitos) → código do segmento no TOTVS (`segmentos.codigo`).
     *
     * Decisão do Tony (2026-10-08): a base `sistema` é de supermercados. Atacadista de
     * alimentos (4639-7/01) ficou de fora até alguém decidir.
     */
    public const MAPA = [
        '4711301' => Segmento::CODIGO_SUPERMERCADISTA, // hipermercados
        '4711302' => Segmento::CODIGO_SUPERMERCADISTA, // supermercados
        '4712100' => Segmento::CODIGO_SUPERMERCADISTA, // minimercados, mercearias e armazéns
    ];

    public static function segmentoDoCnae(?string $cnae): ?string
    {
        return self::MAPA[(string) $cnae] ?? null;
    }

    /**
     * @return array{classificados: array<string, int>, semCnae: int, foraDoMapa: int, cnaesForaDoMapa: array<string, int>}
     *         `classificados` é nome do segmento → quantos; `cnaesForaDoMapa`, os 10 CNAEs
     *         mais comuns entre os que ficaram sem segmento (é a lista para decidir o próximo).
     */
    public function classificar(bool $dryRun = false): array
    {
        $leads = DB::table('leads')
            ->whereIn('origem', Lead::ORIGENS_IMPORTADAS)
            ->where(fn ($q) => $q->whereNull('segmento')->orWhere('segmento', ''))
            ->whereNotNull('cnpj')
            ->get(['id', 'cnpj'])
            ->map(fn ($l) => [$l->id, preg_replace('/\D/', '', (string) $l->cnpj)])
            ->filter(fn (array $l) => strlen($l[1]) === 14);

        $cnaes = [];
        foreach ($leads->pluck(1)->unique()->chunk(1000) as $lote) {
            $cnaes += DB::table('cnpj_situacoes')->whereIn('cnpj', $lote->values()->all())
                ->whereNotNull('cnae_principal')
                ->pluck('cnae_principal', 'cnpj')->all();
        }

        $nomes = Segmento::query()->whereIn('codigo', array_unique(self::MAPA))->pluck('nome', 'codigo');

        $porSegmento = [];
        $semCnae = 0;
        $foraDoMapa = [];

        foreach ($leads as [$id, $cnpj]) {
            $cnae = $cnaes[$cnpj] ?? null;

            if ($cnae === null) {
                $semCnae++;

                continue;
            }

            $nome = $nomes[self::segmentoDoCnae($cnae)] ?? null;

            if ($nome === null) {
                $foraDoMapa[$cnae] = ($foraDoMapa[$cnae] ?? 0) + 1;

                continue;
            }

            $porSegmento[$nome][] = $id;
        }

        if (! $dryRun) {
            foreach ($porSegmento as $nome => $ids) {
                foreach (array_chunk($ids, 1000) as $lote) {
                    // Sem `updated_at`: classificar não é editar o lead.
                    DB::table('leads')->whereIn('id', $lote)->update(['segmento' => $nome]);
                }
            }
        }

        arsort($foraDoMapa);

        return [
            'classificados' => array_map('count', $porSegmento),
            'semCnae' => $semCnae,
            'foraDoMapa' => array_sum($foraDoMapa),
            'cnaesForaDoMapa' => array_slice($foraDoMapa, 0, 10, true),
        ];
    }
}
