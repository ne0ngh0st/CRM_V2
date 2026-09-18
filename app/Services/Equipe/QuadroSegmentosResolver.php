<?php

namespace App\Services\Equipe;

use App\Models\Segmento;
use App\Models\User;
use App\Services\Carteira\SegmentosDoVendedorResolver;

/**
 * Quem atende cada segmento, no grão da equipe visível.
 *
 * A lista de `/equipe` é filtrável (estado, login, online…). Este quadro NÃO
 * herda esses filtros: a pergunta é "quem é de cada segmento na equipe", e um
 * filtro de último login faria a cobertura mentir. O escopo por perfil
 * ({@see EquipeScopeResolver::codigosEquipe}) continua valendo — supervisor
 * vê a própria equipe, admin/diretor vêem todo mundo.
 *
 * ⚠️ Só entra quem tem `cod_vendedor` e está ativo. Sem código não há onde
 * gravar o segmento; inativo não atende carteira. Os dois ficam de fora de
 * propósito, não por esquecimento — a lista de usuários continua mostrando.
 */
class QuadroSegmentosResolver
{
    public function __construct(
        private readonly EquipeScopeResolver $scope,
        private readonly SegmentosDoVendedorResolver $segmentosDoVendedor,
    ) {
    }

    /**
     * @return array{
     *     pessoas: list<array<string, mixed>>,
     *     segmentos: list<array{id: int, codigo: string, nome: string}>,
     *     totais: array{pessoas: int, comSegmento: int, semSegmento: int, segmentosComGente: int},
     *     codigoSupermercadista: string,
     * }
     */
    public function montar(User $viewer): array
    {
        $usuarios = $this->queryDoEscopo($viewer)->get();

        $codigos = $usuarios
            ->map(fn (User $u) => $u->vendedorPerfil?->cod_vendedor)
            ->filter()
            ->values();

        $segmentosPorCodigo = $this->segmentosDoVendedor->porCodigo($codigos);
        $vezesPorCodigo = $codigos->countBy()->all();

        $pessoas = $usuarios
            ->map(function (User $u) use ($segmentosPorCodigo, $vezesPorCodigo) {
                $cod = $u->vendedorPerfil->cod_vendedor;
                $segmentos = $segmentosPorCodigo[$cod] ?? collect();

                return [
                    'id' => $u->id,
                    'nome' => $u->display_name ?: $u->name,
                    'perfil' => $u->getRoleNames()->first(),
                    'fotoUrl' => $u->foto_url,
                    'codVendedor' => $cod,
                    'estado' => $u->estado,
                    'segmentosIds' => $segmentos->pluck('id')->values()->all(),
                    'compartilhado' => ($vezesPorCodigo[$cod] ?? 0) > 1,
                ];
            })
            ->sortBy(fn (array $p) => mb_strtoupper($p['nome']), SORT_NATURAL)
            ->values();

        $comSegmento = $pessoas->filter(fn (array $p) => $p['segmentosIds'] !== [])->count();
        $idsComGente = $pessoas->pluck('segmentosIds')->flatten()->unique()->count();

        return [
            'pessoas' => $pessoas->all(),
            'segmentos' => Segmento::query()
                ->orderBy('nome')
                ->get(['id', 'codigo', 'nome'])
                ->map(fn (Segmento $s) => [
                    'id' => $s->id,
                    'codigo' => $s->codigo,
                    'nome' => $s->nome,
                ])
                ->all(),
            'totais' => [
                'pessoas' => $pessoas->count(),
                'comSegmento' => $comSegmento,
                'semSegmento' => $pessoas->count() - $comSegmento,
                'segmentosComGente' => $idsComGente,
            ],
            'codigoSupermercadista' => Segmento::CODIGO_SUPERMERCADISTA,
        ];
    }

    private function queryDoEscopo(User $viewer)
    {
        $codigos = $this->scope->codigosEquipe($viewer);

        $query = User::query()
            ->with(['vendedorPerfil', 'roles'])
            ->where('is_active', true)
            ->whereHas('vendedorPerfil', function ($q) use ($codigos) {
                $q->whereNotNull('cod_vendedor')->where('cod_vendedor', '!=', '');

                if ($codigos === null) {
                    return;
                }

                if ($codigos === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->whereIn('cod_vendedor', $codigos);
            });

        return $query;
    }
}
