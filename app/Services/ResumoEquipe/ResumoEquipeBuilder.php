<?php

namespace App\Services\ResumoEquipe;

use App\Models\Ligacao;
use App\Models\User;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Contatos\ContatosPorUsuario;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Support\Collection;

/**
 * Monta o "retrato do dia" de uma equipe para o e-mail diário dos gestores.
 *
 * ⚠️ NÃO CALCULA NADA DE NOVO. Cada número vem da mesma peça que o mostra na tela:
 *
 *   venda / faturamento / meta ... MetaRankingResolver::ranking()  (o mesmo do /metas)
 *   pedidos emitidos ............. MetaRankingResolver::pedidosPorCodigo()
 *   contatos por canal ........... ContatosPorUsuario             (o mesmo da Visão do Gestor)
 *   ativos/perdendo/a trabalhar .. ClienteStatusResolver::contagem()
 *
 * Um e-mail que diz um número e uma tela que diz outro é pior que não ter e-mail: o
 * gestor deixa de confiar nos dois (Regra de ouro nº 8, e a regra "os números têm que
 * bater" do Painel).
 *
 * ⚠️ QUEM É A EQUIPE: {@see EquipeScopeResolver::codigosEquipeDe()} — quem tem
 * `cod_super` igual ao código do gestor, MAIS o próprio gestor. É a regra das telas de
 * gestão (/equipe, /metas), não a do Painel (lá o supervisor em modo Equipe vê a equipe
 * PURA). A regra decide pelo `cod_super`, não pelo perfil — é o que faz o Beto (diretor
 * com representantes abaixo dele) funcionar igual a um supervisor.
 *
 * ⚠️ JANELAS: venda, faturamento e pedidos vão até D-1 (D-3 na segunda), igual ao Painel
 * e ao /metas; contatos vão até AGORA. O e-mail declara as duas datas no cabeçalho.
 */
class ResumoEquipeBuilder
{
    public function __construct(
        private readonly EquipeScopeResolver $equipes,
        private readonly MetaRankingResolver $metas,
        private readonly ContatosPorUsuario $contatos,
        private readonly ClienteStatusResolver $carteira,
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

        $secao = $this->secao($this->nomeDe($gestor), $codigos);

        return [
            'tipo' => 'equipe',
            'titulo' => 'Equipe '.$this->primeiroNome($gestor),
            'secoes' => [$secao],
            'totais' => $secao['totais'],
            'vendedores' => $secao['totais']['vendedores'],
            'periodo' => $this->periodo(),
        ];
    }

    /**
     * Todas as equipes cujos gestores recebem o resumo `equipe`, uma seção por gestor.
     *
     * ⚠️ O conjunto de equipes É a lista de quem recebe `equipe` — e não uma lista fixa de
     * nomes. Gestor novo marcado na tela Equipe entra sozinho no consolidado do Paulo e
     * do Leandro, e quem sair sai junto.
     *
     * ⚠️ O TOTAL NÃO É A SOMA DAS SEÇÕES: é recalculado sobre a UNIÃO dos códigos. Um
     * supervisor pode estar debaixo de um diretor (o `cod_super` do supervisor aponta para
     * o diretor), e aí o próprio código dele aparece em duas equipes. Somar as seções
     * contaria a carteira dele duas vezes; a união conta uma.
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

            $secoes[] = $this->secao($this->nomeDe($gestor), $codigos);
            $uniao = [...$uniao, ...$codigos];
        }

        // Ranking das equipes: melhor % de venda no mês primeiro, sem meta por último.
        usort($secoes, fn (array $a, array $b) => $this->compararPct($a['totais']['vendaPct'], $b['totais']['vendaPct'])
            ?: strcmp($a['nome'], $b['nome']));

        $totais = $uniao === []
            ? $this->totaisVazios()
            : $this->secao('Total', array_values(array_unique($uniao)))['totais'];

        return [
            'tipo' => 'consolidado',
            'titulo' => 'Visão consolidada das equipes',
            'secoes' => $secoes,
            'totais' => $totais,
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
     * Uma equipe: linha por pessoa + totais.
     *
     * @param  list<string>  $codigos
     * @return array{nome: string, linhas: list<array<string, mixed>>, totais: array<string, mixed>}
     */
    private function secao(string $nome, array $codigos): array
    {
        $ano = (int) now()->year;
        $mes = (int) now()->month;

        $ranking = $this->metas->ranking($codigos, $ano, $mes);
        $linhasRanking = collect($ranking['linhas']);

        $ids = $linhasRanking->pluck('userId')->map(fn ($id) => (int) $id)->all();
        $agora = now()->toDateTimeString();
        $hoje = $this->contatos->porUsuario($ids, now()->startOfDay()->toDateTimeString(), $agora);
        $noMes = $this->contatos->porUsuario($ids, now()->startOfMonth()->toDateTimeString(), $agora);

        $pedidos = $this->metas->pedidosPorCodigo($codigos, $ano, $mes);
        $carteiraPorVendedor = $this->carteira->contagem($codigos, porVendedor: true);

        $vazioContato = ['total' => 0, 'porCanal' => Ligacao::lerPorCanal(null)];
        $vazioCarteira = ['ativos' => 0, 'inativando' => 0, 'inativos' => 0, 'total' => 0];

        $linhas = $linhasRanking->map(fn (array $l) => [
            'nome' => $l['nome'],
            'codVendedor' => $l['codVendedor'],
            'perfil' => $l['perfil'],
            'contatosHoje' => $hoje[$l['userId']] ?? $vazioContato,
            'contatosMes' => $noMes[$l['userId']] ?? $vazioContato,
            'pedidos' => $pedidos[$l['codVendedor']] ?? 0,
            'vendaRealizado' => $l['vendaRealizado'],
            'vendaMeta' => $l['vendaMeta'],
            'vendaPct' => $l['vendaPct'],
            'fatRealizado' => $l['fatRealizado'],
            'fatMeta' => $l['fatMeta'],
            'fatPct' => $l['fatPct'],
            'carteira' => $this->comPercentuais($carteiraPorVendedor[$l['codVendedor']] ?? $vazioCarteira),
        ])
            ->sort(fn (array $a, array $b) => $this->compararPct($a['vendaPct'], $b['vendaPct'])
                ?: strcmp($a['nome'], $b['nome']))
            ->values();

        return [
            'nome' => $nome,
            'linhas' => $linhas->all(),
            'totais' => $this->totais($linhas, $ranking['totais'], $pedidos, $codigos),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @param  array<string, float|null>  $totaisRanking
     * @param  array<string, int>  $pedidos
     * @param  list<string>  $codigos
     * @return array<string, mixed>
     */
    private function totais(Collection $linhas, array $totaisRanking, array $pedidos, array $codigos): array
    {
        $somarContatos = function (string $campo) use ($linhas): array {
            $porCanal = [];
            foreach (Ligacao::TIPOS_CONTATO as $canal) {
                $porCanal[$canal] = (int) $linhas->sum(fn (array $l) => $l[$campo]['porCanal'][$canal] ?? 0);
            }

            return ['total' => (int) $linhas->sum(fn (array $l) => $l[$campo]['total']), 'porCanal' => $porCanal];
        };

        return [
            'vendedores' => $linhas->count(),
            'contatosHoje' => $somarContatos('contatosHoje'),
            'contatosMes' => $somarContatos('contatosMes'),
            // Por código, não por linha: duas contas que dividem um código mostram o mesmo
            // número nas duas linhas, mas os pedidos são um só.
            'pedidos' => (int) array_sum(array_intersect_key($pedidos, array_flip($linhas->pluck('codVendedor')->unique()->all()))),
            'vendaRealizado' => $totaisRanking['vendaRealizado'],
            'vendaMeta' => $totaisRanking['vendaMeta'],
            'vendaPct' => $totaisRanking['vendaPct'],
            'fatRealizado' => $totaisRanking['fatRealizado'],
            'fatMeta' => $totaisRanking['fatMeta'],
            'fatPct' => $totaisRanking['fatPct'],
            // Da equipe inteira, e não a soma das linhas — ver ClienteStatusResolver::contagem().
            'carteira' => $this->comPercentuais($this->carteira->contagem($codigos)),
        ];
    }

    /**
     * @param  array{ativos: int, inativando: int, inativos: int, total: int}  $c
     * @return array<string, int|float>
     */
    private function comPercentuais(array $c): array
    {
        $pct = fn (int $n) => $c['total'] > 0 ? round($n / $c['total'] * 100, 1) : 0.0;

        return $c + [
            'pctAtivos' => $pct($c['ativos']),
            'pctInativando' => $pct($c['inativando']),
            'pctInativos' => $pct($c['inativos']),
        ];
    }

    /** @return array<string, mixed> */
    private function totaisVazios(): array
    {
        return $this->totais(collect(), [
            'vendaRealizado' => 0.0, 'vendaMeta' => 0.0, 'vendaPct' => null,
            'fatRealizado' => 0.0, 'fatMeta' => 0.0, 'fatPct' => null,
        ], [], []);
    }

    /** @return array{vendaAte: string, contatosAte: string, geradoEm: string} */
    private function periodo(): array
    {
        $fim = $this->metas->fimRealizado((int) now()->year, (int) now()->month);

        return [
            'vendaAte' => $fim->toDateString(),
            'contatosAte' => now()->toDateTimeString(),
            'geradoEm' => now()->toDateTimeString(),
        ];
    }

    private function compararPct(?float $a, ?float $b): int
    {
        if ($a === $b) {
            return 0;
        }
        if ($a === null || $b === null) {
            return $a === null ? 1 : -1;
        }

        return $b <=> $a;
    }

    private function nomeDe(User $u): string
    {
        return $u->display_name ?: $u->name;
    }

    private function primeiroNome(User $u): string
    {
        return mb_convert_case(strtok($this->nomeDe($u), ' ') ?: $this->nomeDe($u), MB_CASE_TITLE);
    }
}
