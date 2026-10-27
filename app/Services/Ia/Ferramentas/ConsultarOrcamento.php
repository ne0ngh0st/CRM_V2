<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Orcamento;
use App\Models\User;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orçamento pelo número, os de um cliente, ou o funil do escopo.
 *
 * O corte é o de `OrcamentoController::baseQuery`: admin e diretor sem filtro veem
 * todos; os demais, só os `user_id` do escopo. Fora do escopo e inexistente devolvem
 * a mesma frase.
 */
final class ConsultarOrcamento implements Ferramenta
{
    private const LIMITE = 8;

    public function __construct(private readonly DashboardScopeResolver $escopo) {}

    public function nome(): string
    {
        return 'consultar_orcamento';
    }

    public function rotuloProgresso(): string
    {
        return 'Consultando orçamentos…';
    }

    public function descricao(): string
    {
        return 'Orçamentos do escopo do usuário. Com numero, abre aquele orçamento (quem fez, '
            .'cliente, valor, desconto, aprovação de margem, aceite do cliente, se virou pedido). '
            .'Com cliente_id (de buscar_cliente), lista os orçamentos daquele cliente. Sem os dois, '
            .'devolve o funil (aguardando margem, aguardando cliente, pronto, virou pedido, perdido) '
            .'e, se etapa vier preenchida, os mais recentes daquela etapa. Use para "quem cuida '
            .'deste orçamento", "o cliente aceitou?", "o que está esperando aprovação".';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'numero' => ['type' => ['integer', 'null'], 'description' => 'Número do orçamento, ou null.'],
                'cliente_id' => ['type' => ['integer', 'null'], 'description' => 'Id de buscar_cliente, ou null.'],
                'etapa' => [
                    'type' => ['string', 'null'],
                    'description' => 'aguardando_margem, aguardando_cliente, pronto, pedido ou perdido. Null para o funil.',
                ],
            ],
            'required' => ['numero', 'cliente_id', 'etapa'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $numero = (int) ($entrada['numero'] ?? 0);
        $clienteId = (int) ($entrada['cliente_id'] ?? 0);
        $etapa = is_string($entrada['etapa'] ?? null) ? $entrada['etapa'] : '';

        if ($etapa !== '' && ! array_key_exists($etapa, Orcamento::ETAPAS)) {
            return ['erro' => 'Etapa inválida. Use: '.implode(', ', array_keys(Orcamento::ETAPAS)).'.'];
        }

        $query = $this->queryDoEscopo($contexto->user);

        if ($numero > 0) {
            $orcamento = $this->comEtapa(clone $query)->whereKey($numero)->first();

            if ($orcamento === null) {
                return [
                    'encontrado' => false,
                    'mensagem' => "Orçamento {$numero} não encontrado no escopo do usuário.",
                ];
            }

            return ['encontrado' => true, 'orcamentos' => [$this->descrever($orcamento)]];
        }

        $funil = $this->funil(clone $query);

        $lista = $this->comEtapa(clone $query)
            ->when($clienteId > 0, fn ($q) => $q->where('cliente_id', $clienteId))
            ->when($etapa !== '', fn ($q) => $q->whereRaw('('.Orcamento::sqlEtapa().') = ?', [$etapa]))
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (Orcamento $o) => $this->descrever($o))
            ->all();

        return [
            'funil' => $funil,
            'orcamentos' => $lista,
            'mensagem' => $lista === [] ? 'Nenhum orçamento nesse recorte.' : null,
        ];
    }

    /** O mesmo corte de `OrcamentoController::baseQuery`, sem os filtros da tela. */
    private function queryDoEscopo(User $user): Builder
    {
        $scope = $this->escopo->resolve($user, null, null);
        $role = $user->getRoleNames()->first();
        $empresaInteira = in_array($role, ['admin', 'diretor'], true) && $scope['codVendedores'] === null;

        return Orcamento::query()
            ->with(['user.vendedorPerfil'])
            ->when(! $empresaInteira, fn ($q) => $q->whereIn('user_id', $this->escopo->usuarioIds($user, $scope)));
    }

    /** @return array<string, mixed> */
    private function funil(Builder $query): array
    {
        $linhas = $query->toBase()
            ->selectRaw('('.Orcamento::sqlEtapa().') AS etapa, COUNT(*) AS qtd, COALESCE(SUM(valor_total), 0) AS valor')
            ->selectRaw("COALESCE(SUM(status_gestor = 'pendente' AND nivel_aprovacao = 'supervisor'), 0) AS aguardando_supervisor")
            ->selectRaw("COALESCE(SUM(status_gestor = 'pendente' AND nivel_aprovacao = 'diretor'), 0) AS aguardando_diretor")
            ->groupByRaw(Orcamento::sqlEtapa())
            ->get()
            ->keyBy('etapa');

        $etapas = [];
        foreach (Orcamento::ETAPAS as $chave => $rotulo) {
            $etapas[] = [
                'etapa' => $rotulo,
                'quantidade' => (int) ($linhas[$chave]->qtd ?? 0),
                'valor' => Formato::reais((float) ($linhas[$chave]->valor ?? 0)),
            ];
        }

        return [
            'etapas' => $etapas,
            'aguardando_o_supervisor' => (int) $linhas->sum('aguardando_supervisor'),
            'aguardando_o_diretor' => (int) $linhas->sum('aguardando_diretor'),
        ];
    }

    private function comEtapa(Builder $query): Builder
    {
        return $query->select('orcamentos.*')->selectRaw('('.Orcamento::sqlEtapa().') as etapa_funil');
    }

    /** @return array<string, mixed> */
    private function descrever(Orcamento $o): array
    {
        $etapa = $o->etapa_funil;

        return [
            'numero' => $o->id,
            'cliente' => $o->cliente_nome,
            'vendedor' => [
                'nome' => $o->user?->display_name ?: $o->user?->name,
                'codigo' => $o->user?->vendedorPerfil?->cod_vendedor,
            ],
            'valor' => Formato::reais($o->valor_total),
            'desconto_maximo' => number_format((float) $o->desconto_pct_max, 2, ',', '.').'%',
            'nivel_de_aprovacao' => $o->nivel_aprovacao,
            'margem' => match ($o->status_gestor) {
                'aprovado' => 'aprovada',
                'rejeitado' => 'rejeitada',
                default => 'aguardando',
            },
            'aceite_do_cliente' => $o->rotuloRespostaCliente() ?? 'aguardando',
            'etapa' => Orcamento::ETAPAS[$etapa] ?? $etapa,
            'pedido_no_sic' => $o->portal_pedido_id,
            'bloqueio_para_virar_pedido' => $o->bloqueioDoPedido(),
            'criado_em' => Formato::data($o->created_at),
        ];
    }
}
