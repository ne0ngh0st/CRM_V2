<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Pedidos\StatusPedidoResolver;
use App\Services\Totvs\Normalizador;
use App\Services\Vendedores\NomeVendedorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista pedidos com filtro — de um vendedor, de um cliente, de um período, numa faixa de
 * atraso — e na ordem que a pessoa pedir.
 *
 * Nasceu das perguntas do diretor que o assistente não respondia: "os atrasados mais
 * recentes", "até 30 dias de atraso", "pedidos deste mês do Paulo", "todos os pedidos
 * deste ano da C6". `consultar_pedido` só acha pelo número e `pedidos_em_atencao` só traz
 * os 8 mais antigos do card do Painel.
 *
 * ⚠️ "Em aberto" é `naCarteiraAberta()`, o mesmo de `/pedidos-abertos` e do card do
 * Painel; "todos" é o universo de `/pedidos-emitidos`. Um terceiro recorte aqui faria o
 * chat contradizer a tela.
 *
 * ⚠️ O escopo é o da tela: vendedor fora do alcance devolve "fora da carteira", nunca a
 * lista dele.
 */
final class ListarPedidos implements Ferramenta
{
    private const LIMITE = 25;

    /** @var array<string, array{0: string, 1: string}> ordem => [coluna, direção] */
    private const ORDENS = [
        'mais_recentes' => ['data_pedido', 'desc'],
        'mais_antigos' => ['data_pedido', 'asc'],
        'maior_valor' => ['valor_total', 'desc'],
        'maior_atraso' => ['data_previsao_faturamento', 'asc'],
        'menor_atraso' => ['data_previsao_faturamento', 'desc'],
    ];

    private const SITUACOES = ['em_aberto', 'atrasados', 'faturados', 'todos'];

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly StatusPedidoResolver $status,
        private readonly NomeVendedorResolver $nomes,
    ) {}

    public function nome(): string
    {
        return 'listar_pedidos';
    }

    public function rotuloProgresso(): string
    {
        return 'Listando os pedidos…';
    }

    public function descricao(): string
    {
        return 'Lista pedidos da carteira do usuário com filtros, devolvendo o total (quantidade '
            .'e valor) e até 25 linhas. Filtros combináveis: situacao (em_aberto, atrasados, '
            .'faturados, todos), vendedor (código de buscar_vendedor), cliente (id de '
            .'buscar_cliente — vale para todas as filiais daquele código), período da data do '
            .'pedido, faixa de dias de atraso, e ordem (mais_recentes, mais_antigos, '
            .'maior_valor, maior_atraso, menor_atraso). Com por_mes true, devolve também a '
            .'quantidade e o valor por mês — use para "compra todo mês?", "frequência". Use para '
            .'"pedidos deste mês do Fulano", "pedidos da empresa X neste ano", "atrasados mais '
            .'recentes", "até 30 dias de atraso", "maiores pedidos em aberto".';
    }

    public function esquema(): array
    {
        $dataOuNulo = ['type' => ['string', 'null'], 'description' => 'AAAA-MM-DD, ou null.'];
        $inteiroOuNulo = ['type' => ['integer', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'situacao' => ['type' => 'string', 'enum' => self::SITUACOES, 'description' => 'atrasados = em aberto com previsão de faturamento vencida.'],
                'vendedor_codigo' => ['type' => ['string', 'null'], 'description' => 'Código de vendedor (de buscar_vendedor), ou null para todo o escopo.'],
                'cliente_id' => ['type' => ['integer', 'null'], 'description' => 'id de buscar_cliente, ou null.'],
                'data_pedido_de' => $dataOuNulo,
                'data_pedido_ate' => $dataOuNulo,
                'atraso_min_dias' => $inteiroOuNulo + ['description' => 'Mínimo de dias de atraso, ou null. Só faz sentido com em_aberto/atrasados.'],
                'atraso_max_dias' => $inteiroOuNulo + ['description' => 'Máximo de dias de atraso ("até 30 dias" = 30), ou null.'],
                'ordem' => ['type' => 'string', 'enum' => array_keys(self::ORDENS)],
                'por_mes' => ['type' => 'boolean', 'description' => 'true para incluir quantidade e valor por mês da data do pedido.'],
            ],
            'required' => ['situacao', 'vendedor_codigo', 'cliente_id', 'data_pedido_de', 'data_pedido_ate', 'atraso_min_dias', 'atraso_max_dias', 'ordem', 'por_mes'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $situacao = in_array($entrada['situacao'] ?? null, self::SITUACOES, true) ? $entrada['situacao'] : 'em_aberto';
        [$coluna, $direcao] = self::ORDENS[$entrada['ordem'] ?? ''] ?? self::ORDENS['mais_recentes'];

        try {
            $de = $this->data($entrada['data_pedido_de'] ?? null);
            $ate = $this->data($entrada['data_pedido_ate'] ?? null);
        } catch (\InvalidArgumentException) {
            return ['erro' => 'Data inválida. Use AAAA-MM-DD.'];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $query = Pedido::query();

        $vendedor = Normalizador::codigoVendedor($entrada['vendedor_codigo'] ?? null);
        if ($vendedor !== null) {
            if (! $this->escopo->alcanca($contexto->user, $vendedor)) {
                return ['encontrado' => false, 'mensagem' => "O código {$vendedor} está fora da carteira que o usuário enxerga."];
            }
            $query->where('cod_vendedor', $vendedor);
        } elseif ($codigos !== null) {
            $query->whereIn('cod_vendedor', $codigos);
        }

        $cliente = null;
        if (($entrada['cliente_id'] ?? null) !== null) {
            $cliente = Cliente::query()->find((int) $entrada['cliente_id']);
            if ($cliente === null || ! $this->escopo->alcanca($contexto->user, $cliente->cod_vendedor)) {
                return ['encontrado' => false, 'mensagem' => 'Cliente não encontrado na carteira do usuário.'];
            }
            // O código inteiro, não só a filial: "pedidos da C6" são de todas as lojas dela.
            $query->whereIn('cliente_id', Cliente::query()->where('cod_cliente', $cliente->cod_cliente)->select('id'));
        }

        $this->aplicarSituacao($query, $situacao, $entrada);

        if ($de !== null) {
            $query->where('data_pedido', '>=', $de->toDateString());
        }
        if ($ate !== null) {
            $query->where('data_pedido', '<=', $ate->toDateString());
        }

        $total = (clone $query)
            ->selectRaw('COUNT(*) as qtd, COALESCE(SUM(valor_total), 0) as valor')
            ->first();

        $pedidos = (clone $query)
            ->with('cliente:id,razao_social,cod_cliente,loja')
            ->orderBy($coluna, $direcao)
            ->orderBy('id', $direcao)
            ->limit(self::LIMITE)
            ->get();

        $nomes = $this->nomes->porCodigo($pedidos->pluck('cod_vendedor'));
        $quantidade = (int) ($total->qtd ?? 0);

        $saida = [
            'escopo' => Formato::escopo($vendedor !== null ? [$vendedor] : $codigos, $contexto->user->getRoleNames()->first()),
            'filtros' => array_filter([
                'situacao' => $situacao,
                'vendedor' => $vendedor,
                'cliente' => $cliente ? $cliente->razao_social.' (código '.$cliente->cod_cliente.', todas as filiais)' : null,
                'data_pedido_de' => Formato::data($de),
                'data_pedido_ate' => Formato::data($ate),
                'atraso_min_dias' => $entrada['atraso_min_dias'] ?? null,
                'atraso_max_dias' => $entrada['atraso_max_dias'] ?? null,
                'ordem' => $entrada['ordem'] ?? 'mais_recentes',
            ], fn ($v) => $v !== null),
            'total' => ['quantidade' => $quantidade, 'valor' => Formato::reais((float) ($total->valor ?? 0))],
            'mostrando' => $pedidos->count(),
            'pedidos' => $pedidos->map(fn (Pedido $p) => $this->linha($p, $nomes))->all(),
            'mensagem' => $quantidade > self::LIMITE
                ? 'Há mais pedidos do que os listados. Diga o total e ofereça estreitar o filtro; a lista inteira está na tela Pedidos (abertos ou emitidos), com Gerar Excel.'
                : ($quantidade === 0 ? 'Nenhum pedido com esses filtros.' : null),
        ];

        if ($entrada['por_mes'] ?? false) {
            $saida['por_mes'] = (clone $query)
                ->selectRaw("DATE_FORMAT(data_pedido, '%Y-%m') as mes, COUNT(*) as qtd, SUM(valor_total) as valor")
                ->groupBy('mes')
                ->orderBy('mes')
                ->get()
                ->map(fn ($m) => ['mes' => $m->mes, 'pedidos' => (int) $m->qtd, 'valor' => Formato::reais((float) $m->valor)])
                ->all();
        }

        return $saida;
    }

    private function aplicarSituacao(Builder $query, string $situacao, array $entrada): void
    {
        $hoje = today();
        $min = $entrada['atraso_min_dias'] ?? null;
        $max = $entrada['atraso_max_dias'] ?? null;
        $porAtraso = $min !== null || $max !== null;

        match ($situacao) {
            'faturados' => $query->whereNotNull('data_faturamento'),
            'todos' => null,
            default => $query->naCarteiraAberta(),
        };

        if ($situacao === 'atrasados' || $porAtraso) {
            // Atraso = dias desde a previsão de faturamento vencida (a conta do card).
            $query->whereNull('data_faturamento')
                ->where('data_previsao_faturamento', '<', $hoje->toDateString())
                ->when($min !== null, fn ($q) => $q->where('data_previsao_faturamento', '<=', $hoje->copy()->subDays(max(1, (int) $min))->toDateString()))
                ->when($max !== null, fn ($q) => $q->where('data_previsao_faturamento', '>=', $hoje->copy()->subDays((int) $max)->toDateString()));
        }
    }

    private function data(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $data = CarbonImmutable::createFromFormat('!Y-m-d', (string) $valor);

        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new \InvalidArgumentException('data');
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $nomes
     * @return array<string, mixed>
     */
    private function linha(Pedido $p, array $nomes): array
    {
        $emAberto = $p->data_faturamento === null;
        $atraso = $emAberto && $p->data_previsao_faturamento?->lt(today())
            ? (int) $p->data_previsao_faturamento->diffInDays(today())
            : null;

        return [
            'numero' => $p->numero_pedido,
            'data_pedido' => Formato::data($p->data_pedido),
            'cliente' => $p->cliente?->razao_social,
            'codigo_cliente' => $p->cliente?->codigoTotvs(),
            'vendedor' => $nomes[$p->cod_vendedor] ?? $p->cod_vendedor,
            'valor' => Formato::reais($p->valor_total),
            'situacao' => $emAberto ? 'em aberto' : 'faturado em '.Formato::data($p->data_faturamento),
            'etapa_totvs' => $emAberto ? $this->status->paraTela($p)['statusRotulo'] : null,
            'previsao_faturamento' => Formato::data($p->data_previsao_faturamento),
            'dias_de_atraso' => $atraso,
        ];
    }
}
