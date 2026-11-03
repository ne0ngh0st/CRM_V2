<?php

namespace App\Services\Equipe;

use App\Models\Segmento;
use App\Models\User;
use App\Services\Carteira\SegmentosDoVendedorResolver;
use App\Services\Segmentos\EspecialistasDoSegmento;

/**
 * Quem atende cada segmento, na tela Visão Gestor → Segmentos: os vendedores internos das
 * equipes de {@see self::EQUIPES_INTERNAS}. Representante não entra.
 *
 * A lista de `/equipe` é filtrável (estado, login, online…). Este quadro NÃO
 * herda esses filtros: a pergunta é "quem é de cada segmento na equipe", e um
 * filtro de último login faria a cobertura mentir. O escopo por perfil
 * ({@see EquipeScopeResolver::codigosEquipe}) continua valendo — supervisor
 * vê a própria equipe, admin/diretor vêem as duas equipes.
 *
 * ⚠️ Só entra quem tem `cod_vendedor` e está ativo. Sem código não há onde
 * gravar o segmento; inativo não atende carteira. Os dois ficam de fora de
 * propósito, não por esquecimento — a lista de usuários continua mostrando.
 */
class QuadroSegmentosResolver
{
    /** Código dos gestores das equipes internas: Américo e Sandra (decisão do Tony, 2026-10-30). */
    public const EQUIPES_INTERNAS = ['010389', '000115'];

    public function __construct(
        private readonly EquipeScopeResolver $scope,
        private readonly SegmentosDoVendedorResolver $segmentosDoVendedor,
        private readonly EspecialistasDoSegmento $especialistas,
    ) {
    }

    /**
     * @return array{
     *     pessoas: list<array<string, mixed>>,
     *     segmentos: list<array{id: int, codigo: string, nome: string}>,
     *     totais: array{pessoas: int, comSegmento: int, semSegmento: int, segmentosComGente: int},
     * }
     */
    public function montar(User $viewer): array
    {
        $usuarios = $this->queryDoEscopo($viewer)->get()
            ->reject(fn (User $u) => $u->roles->contains('name', 'representante'))
            ->values();

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
        $especialistas = $this->especialistas->porCodigo();

        return [
            'pessoas' => $pessoas->all(),
            'segmentos' => Segmento::query()
                ->orderBy('nome')
                ->get(['id', 'codigo', 'nome'])
                ->map(fn (Segmento $s) => [
                    'id' => $s->id,
                    'codigo' => $s->codigo,
                    'nome' => $s->nome,
                    /*
                     * Quem tem a estrela. Pode ser alguém FORA do escopo deste quadro (o
                     * supervisor vê só a equipe dele, e o especialista pode ser de outra) —
                     * por isso vem o objeto inteiro, não só o id.
                     */
                    'especialista' => $especialistas[(string) $s->codigo] ?? null,
                ])
                ->all(),
            'totais' => [
                'pessoas' => $pessoas->count(),
                'comSegmento' => $comSegmento,
                'semSegmento' => $pessoas->count() - $comSegmento,
                'segmentosComGente' => $idsComGente,
            ],
        ];
    }

    private function queryDoEscopo(User $viewer)
    {
        $codigos = $this->scope->codigosEquipe($viewer)
            ?? collect(self::EQUIPES_INTERNAS)
                ->flatMap(fn (string $gestor) => $this->scope->codigosEquipeDe($gestor))
                ->unique()
                ->values()
                ->all();

        $query = User::query()
            ->with(['vendedorPerfil', 'roles'])
            ->where('is_active', true)
            ->whereHas('vendedorPerfil', function ($q) use ($codigos) {
                $q->whereNotNull('cod_vendedor')->where('cod_vendedor', '!=', '');

                if ($codigos === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->whereIn('cod_vendedor', $codigos);
            });

        return $query;
    }
}
