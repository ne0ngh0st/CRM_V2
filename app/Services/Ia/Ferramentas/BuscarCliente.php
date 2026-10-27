<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;

/**
 * Acha clientes DA CARTEIRA do usuário por nome, CNPJ ou código. Devolve o `id` que o
 * `resumo_cliente` precisa.
 *
 * A lógica de casamento é a do "Quem cuida do cliente?" (`BuscaTitularidade`), com o
 * escopo ligado — mesmos índices, mesmos números medidos.
 */
final class BuscarCliente implements Ferramenta
{
    private const LIMITE = 10;

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly BuscaTitularidade $busca,
    ) {}

    public function nome(): string
    {
        return 'buscar_cliente';
    }

    public function rotuloProgresso(): string
    {
        return 'Procurando o cliente na sua carteira…';
    }

    public function descricao(): string
    {
        return 'Procura clientes da carteira do usuário pelo começo do nome (razão social ou '
            .'fantasia), CNPJ/CPF ou código do TOTVS. Devolve até 10, com o id para usar em '
            .'resumo_cliente, numeros_do_cliente e consultar_orcamento. Se não achar, o cliente '
            .'pode ser de outra carteira: aí use quem_cuida_do_cliente.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => ['type' => 'string', 'description' => 'Nome, CNPJ/CPF ou código do cliente (mínimo 3 caracteres).'],
            ],
            'required' => ['termo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $termo = trim((string) ($entrada['termo'] ?? ''));

        if (mb_strlen($termo) < BuscaTitularidade::MINIMO_CARACTERES) {
            return ['clientes' => [], 'mensagem' => 'Use pelo menos 3 caracteres.'];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $clientes = $this->busca->clientesQueCasam($termo, $codigos, self::LIMITE);

        return [
            'clientes' => $clientes->map(fn (Cliente $c) => [
                'id' => $c->id,
                'razao_social' => $c->razao_social,
                'nome_fantasia' => $c->nome_fantasia,
                'cnpj' => $c->cnpj,
                'codigo_totvs' => $c->codigoTotvs(),
            ])->values()->all(),
            'mensagem' => $clientes->isEmpty() ? 'Nenhum cliente com esse termo na carteira do usuário.' : null,
        ];
    }
}
