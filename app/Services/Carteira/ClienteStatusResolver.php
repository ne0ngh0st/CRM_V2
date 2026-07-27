<?php

namespace App\Services\Carteira;

use App\Models\Cliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Status de carteira (ativo/inativando/inativo) a partir de `clientes.data_ultima_compra`
 * — campo espelhado do TOTVS junto com o resto do cadastro do cliente (igual
 * razao_social/estado), não calculado aqui. Nunca consulta `faturamentos` nem faz
 * JOIN/GROUP BY ao vivo — é por isso que essa mesma classe serve tanto pra um
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
}
