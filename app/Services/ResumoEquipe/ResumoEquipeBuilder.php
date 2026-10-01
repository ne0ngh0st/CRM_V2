<?php

namespace App\Services\ResumoEquipe;

use App\Models\Faturamento;
use App\Models\Ligacao;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Contatos\ContatosPorUsuario;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monta o "retrato do dia" de uma equipe para o e-mail diário dos gestores.
 *
 * O e-mail é DIÁRIO (validado com o Leandro em 2026-09-28): a leitura principal é uma
 * linha só — contatos, pedidos, venda e faturamento do dia —, repetida por vendedor. O
 * mês contra a meta fica no "resumão" do rodapé, só no total.
 *
 * 🚨 OS NÚMEROS TÊM QUE BATER COM O POWER BI (Tony, 2026-09-28). As views `vw_bi_fato_*`
 * leem as mesmas tabelas SEM filtro de vendedor, então:
 *
 *   - a EQUIPE soma TODOS os códigos dela ({@see EquipeScopeResolver::codigosEquipeDe()},
 *     o gestor incluído), qualquer que seja o perfil de quem os usa. A primeira versão
 *     partia do ranking do /metas, que só lista vendedor/representante/supervisor ativos
 *     — e a equipe do Beto (diretor) saía sem os R$ 23,7 mi do próprio código dele;
 *   - o CONSOLIDADO é a EMPRESA INTEIRA, não a soma das equipes marcadas. Quem vende
 *     fora delas (Paulo de Tarso, Natany, almoxarifado virtual…) entra na seção "Fora das
 *     equipes", e o que não tem vendedor nenhum fecha a conta numa linha própria. Antes
 *     disso o consolidado mostrava R$ 11 mi de faturamento contra R$ 55 mi no BI.
 *
 * ⚠️ NÃO CALCULA NADA DE NOVO: venda/faturamento/meta pelo MetaRankingResolver (a régua
 * do /metas e do Painel), contatos pelo ContatosPorUsuario (o da Visão do Gestor).
 *
 * ⚠️ DOIS "DIAS" NA MESMA LINHA, de propósito: contatos são de HOJE (vêm do CRM, ao
 * vivo); pedidos, venda e faturamento são do último dia FECHADO
 * ({@see MetaRankingResolver::ultimoDiaFechado()}), porque às 18h o TOTVS de hoje ainda
 * não entrou — "venda de hoje" sairia zero todo dia. O e-mail escreve as duas datas.
 */
class ResumoEquipeBuilder
{
    public function __construct(
        private readonly EquipeScopeResolver $equipes,
        private readonly MetaRankingResolver $metas,
        private readonly ContatosPorUsuario $contatos,
    ) {}

    /**
     * O resumo que um destinatário recebe, conforme `users.resumo_diario`.
     * `null` = não há nada a mandar (não recebe, ou é gestor sem equipe).
     *
     * @return array<string, mixed>|null
     */
    public function paraDestinatario(User $destinatario): ?array
    {
        return match ($destinatario->resumo_diario) {
            'equipe' => $this->equipe($destinatario),
            'consolidado' => $this->consolidado(),
            default => null,
        };
    }

    /**
     * A equipe de um gestor. `null` quando ninguém aponta para o código dele — não se
     * manda e-mail vazio, que leria como "a equipe não fez nada".
     *
     * @return array<string, mixed>|null
     */
    public function equipe(User $gestor): ?array
    {
        $codigos = $this->codigosDaEquipe($gestor);

        if ($codigos === null) {
            return null;
        }

        $secao = $this->secao('Equipe '.$this->nomeTitulo($gestor), $codigos);

        return [
            'tipo' => 'equipe',
            'titulo' => 'Equipe '.$this->primeiroNome($gestor),
            'secoes' => [$secao],
            'totais' => $secao['totais'],
            'resumao' => $this->resumaoDosCodigos($codigos),
            'vendedores' => $secao['totais']['vendedores'],
            'periodo' => $this->periodo(),
        ];
    }

    /**
     * A EMPRESA INTEIRA, aberta por equipe — o número do total do Power BI.
     *
     * ⚠️ As seções são as equipes de quem recebe `equipe` (gestor novo marcado entra
     * sozinho), mais "Fora das equipes". O TOTAL NÃO É A SOMA DAS SEÇÕES: um supervisor
     * pode estar debaixo de um diretor, e aí o código dele aparece em duas equipes. O total
     * é a empresa, lida direto das tabelas.
     *
     * @return array<string, mixed>
     */
    public function consolidado(): array
    {
        $gestores = User::query()
            ->where('is_active', true)
            ->where('resumo_diario', 'equipe')
            ->with('vendedorPerfil')
            ->get()
            ->sortBy(fn (User $u) => mb_strtolower($this->nomeDe($u)))
            ->values();

        $secoes = [];
        $uniao = [];

        foreach ($gestores as $gestor) {
            $codigos = $this->codigosDaEquipe($gestor);
            if ($codigos === null) {
                continue;
            }

            $secoes[] = $this->secao('Equipe '.$this->nomeTitulo($gestor), $codigos)
                + ['codigoGestor' => $gestor->vendedorPerfil?->cod_vendedor];
            $uniao = [...$uniao, ...$codigos];
        }
        $uniao = array_values(array_unique($uniao));

        // Ordem fixa da diretoria primeiro (config 'ordem_equipes'); o resto pela venda do
        // dia, maior primeiro. Fixa porque quem lê procura a mesma equipe no mesmo lugar.
        $ordem = array_flip(config('resumo_equipe.ordem_equipes', []));
        $posicao = fn (array $s) => $ordem[$s['codigoGestor'] ?? ''] ?? PHP_INT_MAX;
        usort($secoes, fn (array $a, array $b) => $posicao($a) <=> $posicao($b)
            ?: $b['totais']['venda'] <=> $a['totais']['venda']
            ?: strcmp($a['nome'], $b['nome']));

        $dia = $this->dia();
        $inicioHoje = now()->startOfDay()->toDateTimeString();
        $agora = now()->toDateTimeString();

        $totais = [
            'vendedores' => 0,
            'contatos' => Ligacao::query()->where('status', '!=', 'excluida')->whereBetween('data_ligacao', [$inicioHoje, $agora])->count(),
            'pedidos' => $this->metas->pedidosTotal($dia, $dia),
            'venda' => $this->metas->realizadoTotal('venda', $dia, $dia),
            'faturamento' => $this->metas->realizadoTotal('faturamento', $dia, $dia),
        ];

        $fora = $this->secaoFora($uniao, $totais);
        if ($fora !== null) {
            $secoes[] = $fora;
        }

        $totais['vendedores'] = collect($secoes)->sum(fn ($s) => count($s['linhas']));

        return [
            'tipo' => 'consolidado',
            'titulo' => 'Visão consolidada da empresa',
            'secoes' => $secoes,
            'totais' => $totais,
            'resumao' => $this->resumaoDaEmpresa(),
            'vendedores' => $totais['vendedores'],
            'periodo' => $this->periodo(),
        ];
    }

    /**
     * Códigos da equipe de um gestor, ou `null` se não há equipe (só ele mesmo, ou nem
     * código tem).
     *
     * @return list<string>|null
     */
    public function codigosDaEquipe(User $gestor): ?array
    {
        $proprio = $gestor->vendedorPerfil?->cod_vendedor;
        $codigos = $this->equipes->codigosEquipeDe($proprio);

        return count($codigos) > 1 ? array_values($codigos) : null;
    }

    /**
     * Uma seção: uma linha por CÓDIGO (qualquer perfil) + o total do dia.
     *
     * O total soma TODOS os códigos; a lista mostra quem está ativo ou mexeu no dia —
     * código de quem saiu da empresa e ficou parado não vira linha de "–" na tabela, mas
     * se vendeu, aparece, senão o total não bateria com a soma do que está na tela.
     *
     * @param  list<string>  $codigos
     * @param  list<int>  $usuariosSemCodigo  quem fez contato sem ter código (só no "Fora")
     * @return array{nome: string, codigos: list<string>, linhas: list<array<string, mixed>>, totais: array<string, mixed>}
     */
    private function secao(string $nome, array $codigos, array $usuariosSemCodigo = []): array
    {
        $dia = $this->dia();
        $venda = $this->metas->realizadoPorCodigo('venda', $codigos, $dia, $dia);
        $faturamento = $this->metas->realizadoPorCodigo('faturamento', $codigos, $dia, $dia);
        $pedidos = $this->metas->pedidosNoPeriodo($codigos, $dia, $dia);

        $perfis = VendedorPerfil::query()->whereIn('cod_vendedor', $codigos)->with('user')->get()
            ->filter(fn (VendedorPerfil $p) => $p->user !== null);
        $semCodigo = User::query()->whereIn('id', $usuariosSemCodigo)->get();

        $ids = [...$perfis->pluck('user_id')->all(), ...$semCodigo->pluck('id')->all()];
        $contatos = $this->contatos->porUsuario(array_map('intval', $ids), now()->startOfDay()->toDateTimeString(), now()->toDateTimeString());
        $contatosDe = fn ($id) => (int) ($contatos[(int) $id]['total'] ?? 0);

        $nomesTotvs = DB::table('vendedores_totvs')->whereIn('codigo', $codigos)->pluck('nome', 'codigo');

        $linhas = collect($codigos)->map(function (string $cod) use ($perfis, $nomesTotvs, $venda, $faturamento, $pedidos, $contatosDe) {
            // Código compartilhado: o nome é o do usuário ativo; os contatos, de todos.
            $donos = $perfis->where('cod_vendedor', $cod)->sortByDesc(fn ($p) => (int) $p->user->is_active)->values();
            $dono = $donos->first()?->user;

            return [
                'nome' => $dono ? ($dono->display_name ?: $dono->name) : ($nomesTotvs[$cod] ?? $cod),
                'codVendedor' => $cod,
                'ativo' => $donos->contains(fn ($p) => $p->user->is_active),
                'contatos' => (int) $donos->sum(fn ($p) => $contatosDe($p->user_id)),
                'pedidos' => (int) ($pedidos[$cod] ?? 0),
                'venda' => (float) ($venda[$cod] ?? 0),
                'faturamento' => (float) ($faturamento[$cod] ?? 0),
            ];
        })->concat($semCodigo->map(fn (User $u) => [
            'nome' => $u->display_name ?: $u->name,
            'codVendedor' => null,
            'ativo' => (bool) $u->is_active,
            'contatos' => $contatosDe($u->id),
            'pedidos' => 0,
            'venda' => 0.0,
            'faturamento' => 0.0,
        ]));

        $visiveis = $this->ordenar($linhas->filter(fn (array $l) => $l['ativo'] || $this->mexeu($l)));

        return [
            'nome' => $nome,
            'codigos' => array_values($codigos),
            'linhas' => $visiveis->all(),
            'totais' => [
                'vendedores' => $visiveis->count(),
                'contatos' => (int) $linhas->sum('contatos'),
                'pedidos' => (int) array_sum($pedidos),
                'venda' => (float) array_sum($venda),
                'faturamento' => (float) array_sum($faturamento),
            ],
        ];
    }

    /**
     * Quem mexeu no dia sem estar em equipe nenhuma, e o que não tem vendedor. `null` se
     * não houver nada — seção vazia é ruído.
     *
     * Fecha a conta por construção: o que sobra entre o total da empresa e o que as
     * equipes + os códigos de fora explicam (nota sem vendedor, código em branco) vira a
     * linha "Sem vendedor". Assim o total do e-mail é sempre o do BI.
     *
     * @param  list<string>  $uniao
     * @param  array<string, int|float>  $empresa
     * @return array<string, mixed>|null
     */
    private function secaoFora(array $uniao, array $empresa): ?array
    {
        $dia = $this->dia();

        $codigosFora = Pedido::query()->contaComoVenda()->where('data_pedido', $dia)->distinct()->pluck('cod_vendedor')
            ->merge(Faturamento::query()->where('data_emissao', $dia)->distinct()->pluck('cod_vendedor'))
            ->filter(fn ($c) => $c !== null && $c !== '' && ! in_array($c, $uniao, true))
            ->unique()->values()->all();

        // Quem fez contato hoje e não aparece em equipe nenhuma nem nos códigos acima.
        $cobertos = VendedorPerfil::query()->whereIn('cod_vendedor', [...$uniao, ...$codigosFora])->pluck('user_id')->all();
        $usuariosSemCodigo = Ligacao::query()
            ->where('status', '!=', 'excluida')
            ->whereBetween('data_ligacao', [now()->startOfDay()->toDateTimeString(), now()->toDateTimeString()])
            ->whereNotIn('usuario_id', $cobertos)
            ->distinct()->pluck('usuario_id')->map(fn ($id) => (int) $id)->all();

        $fora = $this->secao('Fora das equipes', $codigosFora, $usuariosSemCodigo);

        // O que a empresa tem e nenhuma linha explica (nota sem vendedor, código vazio).
        $explicado = [
            'pedidos' => array_sum($this->metas->pedidosNoPeriodo($uniao, $dia, $dia)),
            'venda' => array_sum($this->metas->realizadoPorCodigo('venda', $uniao, $dia, $dia)),
            'faturamento' => array_sum($this->metas->realizadoPorCodigo('faturamento', $uniao, $dia, $dia)),
        ];
        $resto = [];
        foreach (['pedidos', 'venda', 'faturamento'] as $campo) {
            $resto[$campo] = $empresa[$campo] - $explicado[$campo] - $fora['totais'][$campo];
        }

        if (abs($resto['venda']) >= 0.005 || abs($resto['faturamento']) >= 0.005 || $resto['pedidos'] != 0) {
            $fora['linhas'][] = [
                'nome' => 'Sem vendedor',
                'codVendedor' => null,
                'ativo' => false,
                'contatos' => 0,
                'pedidos' => (int) $resto['pedidos'],
                'venda' => (float) $resto['venda'],
                'faturamento' => (float) $resto['faturamento'],
            ];
            foreach (['pedidos', 'venda', 'faturamento'] as $campo) {
                $fora['totais'][$campo] += $resto[$campo];
            }
            $fora['totais']['vendedores'] = count($fora['linhas']);
        }

        return $fora['linhas'] === [] ? null : $fora;
    }

    /**
     * O "resumão" do rodapé: o mês contra a meta, só no total.
     *
     * Acumulado do ano e saúde da carteira saíram por decisão do Tony/Leandro
     * (2026-09-28): o e-mail é do DIA, e o que não muda de um dia para o outro vira ruído.
     *
     * @param  list<string>  $codigos
     * @return array{mes: array<string, array{realizado: float, meta: float, pct: float|null}>}
     */
    private function resumaoDosCodigos(array $codigos): array
    {
        [$ano, $mes, $inicio, $fim] = $this->janelaDoMes();

        return ['mes' => $this->comPct(fn (string $tipo) => [
            (float) array_sum($this->metas->realizadoPorCodigo($tipo, $codigos, $inicio, $fim)),
            (float) array_sum($this->metas->metasPorCodigo($codigos, $ano, $mes, $mes, $tipo)),
        ])];
    }

    /**
     * O resumão da empresa inteira — realizado e meta sem filtro de código, como o BI.
     *
     * @return array{mes: array<string, array{realizado: float, meta: float, pct: float|null}>}
     */
    private function resumaoDaEmpresa(): array
    {
        [$ano, $mes, $inicio, $fim] = $this->janelaDoMes();

        return ['mes' => $this->comPct(fn (string $tipo) => [
            $this->metas->realizadoTotal($tipo, $inicio, $fim),
            $this->metas->metaTotal($ano, $mes, $tipo),
        ])];
    }

    /**
     * @param  callable(string): array{0: float, 1: float}  $realizadoEMeta
     * @return array<string, array{realizado: float, meta: float, pct: float|null}>
     */
    private function comPct(callable $realizadoEMeta): array
    {
        $out = [];
        foreach (['venda', 'faturamento'] as $tipo) {
            [$realizado, $meta] = $realizadoEMeta($tipo);
            $out[$tipo] = [
                'realizado' => $realizado,
                'meta' => $meta,
                'pct' => $meta > 0 ? round($realizado / $meta * 100, 1) : null,
            ];
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: string, 3: string} */
    private function janelaDoMes(): array
    {
        $ano = (int) now()->year;
        $mes = (int) now()->month;

        return [$ano, $mes, now()->startOfMonth()->toDateString(), $this->metas->fimRealizado($ano, $mes)->toDateString()];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return Collection<int, array<string, mixed>>
     */
    private function ordenar(Collection $linhas): Collection
    {
        return $linhas->sort(fn (array $a, array $b) => $b['venda'] <=> $a['venda']
            ?: $b['faturamento'] <=> $a['faturamento']
            ?: $b['contatos'] <=> $a['contatos']
            ?: strcmp($a['nome'], $b['nome']))->values();
    }

    /** @param  array<string, mixed>  $l */
    private function mexeu(array $l): bool
    {
        return $l['contatos'] > 0 || $l['pedidos'] > 0 || $l['venda'] != 0.0 || $l['faturamento'] != 0.0;
    }

    private function dia(): string
    {
        return $this->metas->ultimoDiaFechado()->toDateString();
    }

    /** @return array{dia: string, vendaAte: string, contatosAte: string, geradoEm: string} */
    private function periodo(): array
    {
        return [
            'dia' => $this->dia(),
            'vendaAte' => $this->metas->fimRealizado((int) now()->year, (int) now()->month)->toDateString(),
            'contatosAte' => now()->toDateTimeString(),
            'geradoEm' => now()->toDateTimeString(),
        ];
    }

    private function nomeDe(User $u): string
    {
        return $u->display_name ?: $u->name;
    }

    /** "SANDRA LIMA" → "Sandra Lima": o TOTVS grava nome em maiúsculas. */
    private function nomeTitulo(User $u): string
    {
        return mb_convert_case(mb_strtolower($this->nomeDe($u)), MB_CASE_TITLE);
    }

    private function primeiroNome(User $u): string
    {
        return mb_convert_case(strtok($this->nomeDe($u), ' ') ?: $this->nomeDe($u), MB_CASE_TITLE);
    }
}
