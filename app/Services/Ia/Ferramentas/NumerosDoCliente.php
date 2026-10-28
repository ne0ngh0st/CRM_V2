<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Faturamento (nota) e pedidos em aberto de um cliente.
 *
 * O faturamento sai de `faturamento_cliente_mensal`, a mesma base da Visão Diretor.
 * Somar `faturamentos` ao vivo por cliente leva ~10 s e a tabela não tem índice por
 * código (migration 2026_09_18_110000).
 *
 * ⚠️ O grão do rollup é o CÓDIGO inteiro, todas as filiais, sem corte por vendedor.
 * Quem não alcança todas as filiais daquele código não recebe o total: o número
 * incluiria a nota do colega. Os pedidos em aberto, esses sim, são só das filiais
 * que a pessoa vê.
 */
final class NumerosDoCliente implements Ferramenta
{
    public function __construct(private readonly DashboardScopeResolver $escopo) {}

    public function nome(): string
    {
        return 'numeros_do_cliente';
    }

    public function rotuloProgresso(): string
    {
        return 'Somando o faturamento do cliente…';
    }

    public function descricao(): string
    {
        return 'Faturamento (nota fiscal, a mesma base da Visão Diretor) de um cliente, '
            .'pelo id de buscar_cliente: total desde 2018, ano corrente, mês corrente e ano '
            .'anterior, os últimos 13 meses mês a mês (valor e número de notas) e os pedidos em '
            .'aberto das filiais que o usuário vê. O faturamento é do CÓDIGO inteiro, todas as '
            .'filiais. Use para "quanto esse cliente faturou", "compra todo mês?", '
            .'"frequência", '
            .'"quanto esse cliente faturou", "faturamento total", "quanto está em aberto '
            .'nele". Não serve para meta da equipe (desempenho_comercial) nem para um pedido '
            .'específico (consultar_pedido).';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente_id' => ['type' => 'integer', 'description' => 'O id devolvido por buscar_cliente.'],
            ],
            'required' => ['cliente_id'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $cliente = Cliente::query()->find((int) ($entrada['cliente_id'] ?? 0));

        if ($cliente === null || ! $this->escopo->alcanca($contexto->user, $cliente->cod_vendedor)) {
            return ['encontrado' => false, 'mensagem' => 'Cliente não encontrado na carteira do usuário.'];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $donos = Cliente::query()->where('cod_cliente', $cliente->cod_cliente)->pluck('cod_vendedor')->unique();
        $veOCodigoInteiro = $codigos === null || $donos->every(
            fn ($dono) => $dono !== null && in_array($dono, $codigos, true),
        );

        $filiais = Cliente::query()->where('cod_cliente', $cliente->cod_cliente)->count();

        $filiaisVisiveis = Cliente::query()
            ->where('cod_cliente', $cliente->cod_cliente)
            ->when($codigos !== null, fn ($q) => $q->whereIn('cod_vendedor', $codigos))
            ->pluck('id');

        $abertos = Pedido::query()
            ->naCarteiraAberta()
            ->whereIn('cliente_id', $filiaisVisiveis)
            ->selectRaw('COUNT(*) as qtd, COALESCE(SUM(valor_total), 0) as valor')
            ->first();

        $saida = [
            'encontrado' => true,
            'razao_social' => $cliente->razao_social,
            'codigo_totvs' => $cliente->codigoTotvs(),
            'filial_consultada' => $cliente->codigoTotvs(),
            'filiais_do_codigo' => $filiais,
            'definicao' => "Faturamento é nota fiscal somada pelo CÓDIGO {$cliente->cod_cliente} inteiro ({$filiais} filiais), não só pela filial consultada. Diga isso ao apresentar. Não é soma de pedido.",
            'pedidos_em_aberto' => [
                'quantidade' => (int) ($abertos->qtd ?? 0),
                'valor' => Formato::reais((float) ($abertos->valor ?? 0)),
                'recorte' => 'só as filiais deste código que estão na carteira do usuário',
            ],
        ];

        if (! $veOCodigoInteiro) {
            return $saida + [
                'faturamento' => null,
                'mensagem' => 'Há filial deste código com outro vendedor. O faturamento de nota do código inteiro não entra na carteira do usuário.',
            ];
        }

        $meses = DB::table('faturamento_cliente_mensal')
            ->where('cod_cliente', $cliente->cod_cliente)
            ->get(['mes', 'valor_total', 'notas', 'calculado_em'])
            ->each(function ($linha) {
                $linha->mes = substr((string) $linha->mes, 0, 10);
            });

        $inicioAno = now()->startOfYear()->toDateString();
        $mesAtual = now()->startOfMonth()->toDateString();
        $inicioAnoAnterior = now()->subYear()->startOfYear()->toDateString();
        $fimAnoAnterior = now()->subYear()->endOfYear()->startOfMonth()->toDateString();

        return $saida + [
            'faturamento' => [
                'historico' => $this->soma($meses),
                'ano' => $this->soma($meses->where('mes', '>=', $inicioAno)->where('mes', '<=', $mesAtual)),
                'mes' => $this->soma($meses->where('mes', $mesAtual)),
                'ano_anterior' => $this->soma($meses->where('mes', '>=', $inicioAnoAnterior)->where('mes', '<=', $fimAnoAnterior)),
                'ultimos_13_meses' => $this->porMes($meses),
                'atualizado_em' => Formato::data($meses->max('calculado_em') ? \Illuminate\Support\Carbon::parse($meses->max('calculado_em')) : null),
            ],
            'mensagem' => null,
        ];
    }

    /**
     * Mês a mês, com zero explícito no mês sem nota — é o que responde "compra todo mês?".
     * O mês corrente sai marcado como parcial.
     *
     * @param  Collection<int, object>  $meses
     * @return list<array<string, mixed>>
     */
    private function porMes(Collection $meses): array
    {
        $porChave = $meses->keyBy('mes');
        $saida = [];

        for ($i = 12; $i >= 0; $i--) {
            $mes = now()->startOfMonth()->subMonths($i);
            $linha = $porChave[$mes->toDateString()] ?? null;
            $saida[] = [
                'mes' => $mes->format('m/Y'),
                'faturamento' => Formato::reais(round((float) ($linha->valor_total ?? 0), 2)),
                'notas' => (int) ($linha->notas ?? 0),
            ] + ($i === 0 ? ['parcial' => true] : []);
        }

        return $saida;
    }

    /** @param  Collection<int, object>  $meses */
    private function soma(Collection $meses): string
    {
        return Formato::reais(round((float) $meses->sum('valor_total'), 2));
    }
}
