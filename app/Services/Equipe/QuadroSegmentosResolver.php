<?php

namespace App\Services\Equipe;

use App\Models\Segmento;
use App\Models\User;
use App\Services\Carteira\SegmentosDoVendedorResolver;
use App\Services\Segmentos\EspecialistasDoSegmento;
use App\Services\Vendedores\NomeVendedorResolver;

/**
 * Quem atende cada segmento, na tela Visão Gestor → Segmentos: os vendedores internos das
 * equipes de {@see self::EQUIPES_INTERNAS}. Representante não entra.
 *
 * A tela segue a planilha "Divisão Segmentos Mercado" da diretoria (2026-11-03):
 * SUPERVISOR → VENDEDOR → SEGMENTOS. Por isso cada pessoa sai com a `equipe` (código do
 * gestor) e o gestor NÃO é uma linha: ele é o cabeçalho do grupo. Ele (e o assistente, que
 * usa o mesmo código) só vira linha se o código tiver segmento gravado — senão o segmento
 * existiria sem aparecer em lugar nenhum da tela.
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
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {
    }

    /**
     * @return array{
     *     equipes: list<array{codigo: string, nome: string}>,
     *     pessoas: list<array<string, mixed>>,
     *     segmentos: list<array{id: int, codigo: string, nome: string}>,
     *     totais: array{pessoas: int, comSegmento: int, semSegmento: int, segmentosComGente: int},
     * }
     */
    public function montar(User $viewer): array
    {
        $gestores = $this->gestores($viewer);

        $usuarios = $this->queryDoEscopo($gestores)->get()
            ->reject(fn (User $u) => $u->roles->contains('name', 'representante'))
            ->values();

        $codigos = $usuarios
            ->map(fn (User $u) => $u->vendedorPerfil?->cod_vendedor)
            ->filter()
            ->values();

        $segmentosPorCodigo = $this->segmentosDoVendedor->porCodigo($codigos);
        $vezesPorCodigo = $codigos->countBy()->all();

        $pessoas = $usuarios
            ->map(function (User $u) use ($segmentosPorCodigo, $vezesPorCodigo, $gestores) {
                $cod = $u->vendedorPerfil->cod_vendedor;
                $segmentos = $segmentosPorCodigo[$cod] ?? collect();
                $eGestor = in_array($cod, $gestores, true);

                if ($eGestor && $segmentos->isEmpty()) {
                    return null;
                }

                return [
                    'id' => $u->id,
                    'equipe' => $eGestor ? $cod : $u->vendedorPerfil->cod_super,
                    'nome' => $u->display_name ?: $u->name,
                    'perfil' => $u->getRoleNames()->first(),
                    'fotoUrl' => $u->foto_url,
                    'codVendedor' => $cod,
                    'estado' => $u->estado,
                    'segmentosIds' => $segmentos->pluck('id')->values()->all(),
                    'compartilhado' => ($vezesPorCodigo[$cod] ?? 0) > 1,
                ];
            })
            ->filter()
            ->sortBy(fn (array $p) => mb_strtoupper($p['nome']), SORT_NATURAL)
            ->values();

        $comSegmento = $pessoas->filter(fn (array $p) => $p['segmentosIds'] !== [])->count();
        $idsComGente = $pessoas->pluck('segmentosIds')->flatten()->unique()->count();
        $especialistas = $this->especialistas->porCodigo();

        $nomes = $this->nomeVendedor->porCodigo($gestores);

        return [
            // Na ordem de `$gestores`: é a ordem em que as equipes aparecem na tela.
            'equipes' => array_map(
                fn (string $codigo) => ['codigo' => $codigo, 'nome' => $nomes[$codigo] ?? $codigo],
                $gestores,
            ),
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

    /**
     * Quem encabeça cada grupo da tela: admin/diretor veem as equipes internas; o
     * supervisor, a própria.
     *
     * @return list<string>
     */
    private function gestores(User $viewer): array
    {
        if ($this->scope->codigosEquipe($viewer) === null) {
            return self::EQUIPES_INTERNAS;
        }

        $proprio = $viewer->vendedorPerfil?->cod_vendedor;

        return $proprio ? [$proprio] : [];
    }

    /**
     * @param  list<string>  $gestores
     */
    private function queryDoEscopo(array $gestores)
    {
        $codigos = collect($gestores)
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
