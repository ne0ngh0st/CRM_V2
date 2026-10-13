<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategicaVinculo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vínculos com cara de errado (13/10/2026). Nasceu de um caso real: o código 000242 é a
 * ESTAPAR (1.626 estacionamentos) e estava ligado ao CARREFOUR e ao MATEUS, porque um ou
 * dois estacionamentos ficam em loja deles e levam o nome. A sugestão automática puxou o
 * código inteiro, e a tela mostrava o Carrefour com centenas de filiais que não são dele.
 *
 * Duas regras, só avisam (quem decide é quem revisa a rede):
 * - **compartilhado**: o mesmo grupo/código está ligado a outra rede;
 * - **sem o nome**: o vínculo tem `MIN_FILIAIS`+ filiais e menos de `PCT_MINIMO` delas trazem
 *   o nome da rede (palavras de marca de `SugestaoDeVinculo::marca()` ou um apelido) na
 *   razão social ou no nome fantasia.
 *
 * ⚠️ Rede ligada pela RAZÃO SOCIAL (GPA = Cia Brasileira de Distribuição, Assaí = Sendas)
 * cai na segunda regra. O remédio é pôr a razão no "Também conhecida como" — vira termo.
 *
 * Roda só para as redes da página aberta: duas consultas para a página inteira.
 */
class VinculosSuspeitos
{
    public const MIN_FILIAIS = 10;

    public const PCT_MINIMO = 0.10;

    public function __construct(private readonly SugestaoDeVinculo $sugestao)
    {
    }

    /**
     * @param  Collection<int, array>  $redes  linhas do `MaioresPorSegmentoResolver` (id, nome, apelidos, vinculos)
     * @return array<int, list<array{tipo: string, codigo: string, motivo: string, texto: string}>> por conta
     */
    public function porConta(Collection $redes): array
    {
        $vinculos = $redes->flatMap(fn (array $r) => collect($r['vinculos'] ?? [])
            ->map(fn (array $v) => ['conta_id' => $r['id'], 'tipo' => $v['tipo'], 'codigo' => (string) $v['codigo']]));

        if ($vinculos->isEmpty()) {
            return [];
        }

        $alertas = [];
        foreach ($this->compartilhados($vinculos) as $a) {
            $alertas[$a['conta_id']][] = collect($a)->except('conta_id')->all();
        }
        foreach ($this->semONome($redes) as $a) {
            $alertas[$a['conta_id']][] = collect($a)->except('conta_id')->all();
        }

        return $alertas;
    }

    /** @param  Collection<int, array{conta_id: int, tipo: string, codigo: string}>  $vinculos */
    private function compartilhados(Collection $vinculos): array
    {
        $outros = DB::table('conta_estrategica_vinculos as v')
            ->join('contas_estrategicas as c', 'c.id', '=', 'v.conta_id')
            ->where(function ($q) use ($vinculos) {
                foreach ($vinculos->groupBy('tipo') as $tipo => $lista) {
                    $q->orWhere(fn ($t) => $t->where('v.tipo', $tipo)->whereIn('v.codigo', $lista->pluck('codigo')->unique()->all()));
                }
            })
            ->get(['v.conta_id', 'v.tipo', 'v.codigo', 'c.nome'])
            ->groupBy(fn ($v) => "{$v->tipo}:{$v->codigo}");

        $saida = [];
        foreach ($vinculos as $v) {
            $redes = $outros->get("{$v['tipo']}:{$v['codigo']}", collect())
                ->where('conta_id', '!=', $v['conta_id'])
                ->pluck('nome')->unique()->values();

            if ($redes->isNotEmpty()) {
                $saida[] = [
                    'conta_id' => $v['conta_id'],
                    'tipo' => $v['tipo'],
                    'codigo' => $v['codigo'],
                    'motivo' => 'compartilhado',
                    'texto' => 'também ligado a '.$redes->join(', '),
                ];
            }
        }

        return $saida;
    }

    /** @param  Collection<int, array>  $redes */
    private function semONome(Collection $redes): array
    {
        // Termos por rede: palavras de marca (sem as genéricas) e cada apelido inteiro.
        $termos = $redes->flatMap(function (array $r) {
            $palavras = collect($this->sugestao->marca($r['nome']))
                ->filter(fn (string $p) => mb_strlen($p) >= 3 && ! $this->sugestao->palavraGenerica($p));

            return $palavras->concat(BuscaNasRedes::apelidos($r['apelidos'] ?? null))
                ->unique()
                ->map(fn (string $t) => ['conta_id' => $r['id'], 'termo' => $t]);
        })->values();

        $contas = $termos->pluck('conta_id')->unique()->values()->all();
        if ($contas === []) {
            return [];
        }

        $uniao = $termos->map(fn () => 'SELECT ? AS conta_id, ? AS termo')->join(' UNION ALL ');
        $bindingsTermos = $termos->flatMap(fn (array $t) => [$t['conta_id'], $t['termo']])->all();

        $filiais = DB::table('conta_estrategica_vinculos as v')
            ->join('clientes as c', 'c.cod_grupo', '=', 'v.codigo')
            ->where('v.tipo', ContaEstrategicaVinculo::TIPO_GRUPO)
            ->whereIn('v.conta_id', $contas)
            ->select('v.conta_id', 'v.tipo', 'v.codigo', 'c.razao_social', 'c.nome_fantasia')
            ->unionAll(
                DB::table('conta_estrategica_vinculos as v')
                    ->join('clientes as c', 'c.cod_cliente', '=', 'v.codigo')
                    ->where('v.tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)
                    ->whereIn('v.conta_id', $contas)
                    ->select('v.conta_id', 'v.tipo', 'v.codigo', 'c.razao_social', 'c.nome_fantasia')
            );

        $linhas = DB::query()
            ->fromSub($filiais, 'f')
            ->select('f.conta_id', 'f.tipo', 'f.codigo')
            ->selectRaw('COUNT(*) as filiais')
            ->selectRaw(
                "SUM(EXISTS (SELECT 1 FROM ({$uniao}) t WHERE t.conta_id = f.conta_id
                    AND (f.razao_social LIKE CONCAT('%', t.termo, '%') OR f.nome_fantasia LIKE CONCAT('%', t.termo, '%')))) as com_nome",
                $bindingsTermos,
            )
            ->groupBy('f.conta_id', 'f.tipo', 'f.codigo')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_FILIAIS])
            ->get();

        return $linhas
            ->filter(fn ($l) => $l->com_nome < $l->filiais * self::PCT_MINIMO)
            ->map(fn ($l) => [
                'conta_id' => (int) $l->conta_id,
                'tipo' => $l->tipo,
                'codigo' => (string) $l->codigo,
                'motivo' => 'sem_nome',
                'texto' => sprintf('só %d de %d filiais têm o nome da rede', $l->com_nome, $l->filiais),
            ])
            ->values()
            ->all();
    }
}
