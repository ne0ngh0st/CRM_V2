<?php

namespace App\Services\Carteira;

use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Status de carteira (ativo/inativando/inativo) a partir de `clientes.data_ultima_compra`
 * — campo espelhado do TOTVS junto com o resto do cadastro do cliente (igual
 * razao_social/estado), não calculado aqui. Nunca consulta `faturamentos` nem faz
 * JOIN ao vivo (o único GROUP BY é a consolidação por cliente de {@see self::contagem()}) — é por isso que essa mesma classe serve tanto pra um
 * widget da Home quanto pra uma listagem paginada de até ~90k clientes.
 */
class ClienteStatusResolver
{
    public const DIAS_ATIVO = 290;
    public const DIAS_INATIVANDO = 365;

    /**
     * @param Collection<int, Cliente> $clientes
     * @return Collection<int, array{cliente: Cliente, status: string}>
     */
    public function resolverParaClientes(Collection $clientes): Collection
    {
        $hoje = Carbon::now();

        return $clientes->map(fn (Cliente $cliente) => [
            'cliente' => $cliente,
            'status' => $this->statusPara($cliente->data_ultima_compra, $hoje),
        ]);
    }

    public function statusPara(?Carbon $dataUltimaCompra, ?Carbon $hoje = null): string
    {
        if (! $dataUltimaCompra) {
            return 'inativo';
        }

        $dias = $dataUltimaCompra->diffInDays($hoje ?? Carbon::now());

        return match (true) {
            $dias <= self::DIAS_ATIVO => 'ativo',
            $dias <= self::DIAS_INATIVANDO => 'inativando',
            default => 'inativo',
        };
    }

    public function limiteAtivo(): Carbon
    {
        return Carbon::now()->subDays(self::DIAS_ATIVO);
    }

    public function limiteInativando(): Carbon
    {
        return Carbon::now()->subDays(self::DIAS_INATIVANDO);
    }

    /**
     * Ativos / inativando / inativos de uma lista de códigos de vendedor, contando
     * CLIENTES (`cod_cliente`), não filiais — a mesma consolidação do
     * {@see CarteiraAderenciaResolver}: cada cliente vale pela compra MAIS RECENTE entre
     * as suas filiais. Sem aderência de segmento: é o recorte "status" puro, que é o que
     * o resumo diário por e-mail mostra.
     *
     * Com `$porVendedor`, a consolidação é por (vendedor, cliente) — o mesmo número que
     * o card do Painel mostra para cada vendedor sozinho. Sem ele, é o número da equipe.
     *
     * ⚠️ A soma das linhas por vendedor pode passar do total da equipe quando um mesmo
     * cliente tem filiais com vendedores diferentes: ele conta uma vez na carteira de
     * cada um, mas uma vez só na equipe. O total da equipe é o verdadeiro — é o que bate
     * com o card.
     *
     * @param  list<string>  $codigos
     * @return array<string, array{ativos: int, inativando: int, inativos: int, total: int}>|array{ativos: int, inativando: int, inativos: int, total: int}
     */
    public function contagem(array $codigos, bool $porVendedor = false): array
    {
        $vazio = ['ativos' => 0, 'inativando' => 0, 'inativos' => 0, 'total' => 0];

        if ($codigos === []) {
            return $porVendedor ? [] : $vazio;
        }

        $chave = $porVendedor ? 'cod_vendedor' : "''";

        $porCliente = Cliente::query()
            ->toBase()
            ->selectRaw("{$chave} as chave, cod_cliente, MAX(data_ultima_compra) as ultima")
            ->whereIn('cod_vendedor', $codigos)
            ->groupByRaw($porVendedor ? 'cod_vendedor, cod_cliente' : 'cod_cliente');

        $linhas = DB::query()
            ->fromSub($porCliente, 'c')
            ->selectRaw('
                chave,
                SUM(ultima >= ?) as ativos,
                SUM(ultima < ? AND ultima >= ?) as inativando,
                SUM(ultima IS NULL OR ultima < ?) as inativos,
                COUNT(*) as total
            ', [
                $this->limiteAtivo()->toDateString(),
                $this->limiteAtivo()->toDateString(),
                $this->limiteInativando()->toDateString(),
                $this->limiteInativando()->toDateString(),
            ])
            ->groupBy('chave')
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->chave => [
                'ativos' => (int) $l->ativos,
                'inativando' => (int) $l->inativando,
                'inativos' => (int) $l->inativos,
                'total' => (int) $l->total,
            ]])
            ->all();

        return $porVendedor ? $linhas : ($linhas[''] ?? $vazio);
    }
}
