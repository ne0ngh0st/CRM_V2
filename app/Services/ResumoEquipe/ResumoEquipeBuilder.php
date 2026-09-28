<?php

namespace App\Services\ResumoEquipe;

use App\Models\User;
use App\Services\Contatos\ContatosPorUsuario;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Support\Collection;

/**
 * Monta o "retrato do dia" de uma equipe para o e-mail diário dos gestores.
 *
 * O e-mail é DIÁRIO (validado com o Leandro em 2026-09-28): a leitura principal é uma
 * linha só — contatos, pedidos, venda e faturamento do dia —, repetida por vendedor. O
 * mês contra a meta fica no "resumão" do rodapé, só no total.
 *
 * ⚠️ NÃO CALCULA NADA DE NOVO. Cada número vem da mesma peça que o mostra na tela:
 *
 *   venda / faturamento / meta ... MetaRankingResolver (o mesmo do /metas)
 *   pedidos emitidos ............. MetaRankingResolver::pedidosNoPeriodo()
 *   contatos ..................... ContatosPorUsuario   (o mesmo da Visão do Gestor)
 *
 * Um e-mail que diz um número e uma tela que diz outro é pior que não ter e-mail: o
 * gestor deixa de confiar nos dois (Regra de ouro nº 8, e a regra "os números têm que
 * bater" do Painel).
 *
 * ⚠️ QUEM É A EQUIPE: {@see EquipeScopeResolver::codigosEquipeDe()} — quem tem
 * `cod_super` igual ao código do gestor, MAIS o próprio gestor. É a regra das telas de
 * gestão (/equipe, /metas), não a do Painel. Decide pelo `cod_super`, não pelo perfil —
 * é o que faz o Beto (diretor com representantes abaixo dele) funcionar igual a um
 * supervisor.
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

        $secao = $this->secao($this->nomeDe($gestor), $codigos);

        return [
            'tipo' => 'equipe',
            'titulo' => 'Equipe '.$this->primeiroNome($gestor),
            'secoes' => [$secao],
            'totais' => $secao['totais'],
            'resumao' => $this->resumao($secao['codigos']),
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
     * contaria o dia dele duas vezes; a união conta uma.
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

        // Equipes pela venda do dia, maior primeiro.
        usort($secoes, fn (array $a, array $b) => $b['totais']['venda'] <=> $a['totais']['venda']
            ?: strcmp($a['nome'], $b['nome']));

        $total = $uniao === [] ? null : $this->secao('Total', array_values(array_unique($uniao)));

        return [
            'tipo' => 'consolidado',
            'titulo' => 'Visão consolidada das equipes',
            'secoes' => $secoes,
            'totais' => $total['totais'] ?? $this->diaVazio(0),
            'resumao' => $this->resumao($total['codigos'] ?? []),
            'vendedores' => $total['totais']['vendedores'] ?? 0,
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
     * Uma equipe: o dia de cada pessoa + o total do dia.
     *
     * A lista de pessoas é a do ranking do /metas (ativos com código) — assim quem aparece
     * aqui é quem aparece lá, e o resumão soma exatamente esses códigos.
     *
     * @param  list<string>  $codigos
     * @return array{nome: string, codigos: list<string>, linhas: list<array<string, mixed>>, totais: array<string, mixed>}
     */
    private function secao(string $nome, array $codigos): array
    {
        $pessoas = collect($this->metas->ranking($codigos, (int) now()->year, (int) now()->month)['linhas']);
        $codigosAtivos = $pessoas->pluck('codVendedor')->filter()->unique()->values()->all();

        $dia = $this->metas->ultimoDiaFechado()->toDateString();
        $venda = $this->metas->realizadoPorCodigo('venda', $codigosAtivos, $dia, $dia);
        $faturamento = $this->metas->realizadoPorCodigo('faturamento', $codigosAtivos, $dia, $dia);
        $pedidos = $this->metas->pedidosNoPeriodo($codigosAtivos, $dia, $dia);

        $ids = $pessoas->pluck('userId')->map(fn ($id) => (int) $id)->all();
        $contatos = $this->contatos->porUsuario($ids, now()->startOfDay()->toDateTimeString(), now()->toDateTimeString());

        $linhas = $pessoas->map(fn (array $l) => [
            'nome' => $l['nome'],
            'codVendedor' => $l['codVendedor'],
            'perfil' => $l['perfil'],
            'contatos' => (int) ($contatos[$l['userId']]['total'] ?? 0),
            'pedidos' => (int) ($pedidos[$l['codVendedor']] ?? 0),
            'venda' => (float) ($venda[$l['codVendedor']] ?? 0),
            'faturamento' => (float) ($faturamento[$l['codVendedor']] ?? 0),
        ])
            ->sort(fn (array $a, array $b) => $b['venda'] <=> $a['venda']
                ?: $b['contatos'] <=> $a['contatos']
                ?: strcmp($a['nome'], $b['nome']))
            ->values();

        // Venda, faturamento e pedidos por CÓDIGO, não por linha: duas contas que dividem
        // um código mostram o mesmo número nas duas linhas, mas o pedido é um só.
        $totais = $this->diaVazio($linhas->count());
        $totais['contatos'] = (int) $linhas->sum('contatos');
        $totais['pedidos'] = (int) array_sum($pedidos);
        $totais['venda'] = (float) array_sum($venda);
        $totais['faturamento'] = (float) array_sum($faturamento);

        return ['nome' => $nome, 'codigos' => $codigosAtivos, 'linhas' => $linhas->all(), 'totais' => $totais];
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
    private function resumao(array $codigos): array
    {
        $ano = (int) now()->year;
        $mes = (int) now()->month;
        $inicio = now()->startOfMonth()->toDateString();
        $fim = $this->metas->fimRealizado($ano, $mes)->toDateString();

        $out = [];
        foreach (['venda', 'faturamento'] as $tipo) {
            $realizado = (float) array_sum($this->metas->realizadoPorCodigo($tipo, $codigos, $inicio, $fim));
            $meta = (float) array_sum($this->metas->metasPorCodigo($codigos, $ano, $mes, $mes, $tipo));
            $out[$tipo] = [
                'realizado' => $realizado,
                'meta' => $meta,
                'pct' => $meta > 0 ? round($realizado / $meta * 100, 1) : null,
            ];
        }

        return ['mes' => $out];
    }

    /** @return array<string, int|float> */
    private function diaVazio(int $vendedores): array
    {
        return ['vendedores' => $vendedores, 'contatos' => 0, 'pedidos' => 0, 'venda' => 0.0, 'faturamento' => 0.0];
    }

    /** @return array{dia: string, vendaAte: string, contatosAte: string, geradoEm: string} */
    private function periodo(): array
    {
        return [
            'dia' => $this->metas->ultimoDiaFechado()->toDateString(),
            'vendaAte' => $this->metas->fimRealizado((int) now()->year, (int) now()->month)->toDateString(),
            'contatosAte' => now()->toDateTimeString(),
            'geradoEm' => now()->toDateTimeString(),
        ];
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
