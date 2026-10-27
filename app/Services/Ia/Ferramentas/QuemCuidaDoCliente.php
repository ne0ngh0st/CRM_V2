<?php

namespace App\Services\Ia\Ferramentas;

use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;

/**
 * "De quem é este cliente?" — a mesma busca de /cadastros, que IGNORA o escopo de
 * propósito.
 *
 * ⚠️ Devolve só o que a tela devolve: nome, documento, código, responsável e supervisor.
 * Nada de status, compra, contato ou valor (ver o docblock de `BuscaTitularidade`). Se um
 * campo novo entrar lá, entra aqui sozinho — não acrescentar nada só nesta ferramenta.
 */
final class QuemCuidaDoCliente implements Ferramenta
{
    private const LIMITE = 10;

    public function __construct(private readonly BuscaTitularidade $busca) {}

    public function nome(): string
    {
        return 'quem_cuida_do_cliente';
    }

    public function rotuloProgresso(): string
    {
        return 'Verificando quem cuida do cliente…';
    }

    public function descricao(): string
    {
        return 'Diz de qual vendedor (e supervisor) é uma EMPRESA, em toda a empresa, por nome, '
            .'CNPJ/CPF ou código (com ou sem a loja: 015076 ou 015076/0001). Também mostra '
            .'solicitações de cadastro ainda pendentes. Use para "esse CNPJ é de alguém?" ou '
            .'quando buscar_cliente não achar o cliente na carteira. Não serve para achar o '
            .'código de uma pessoa da Autopel — isso é buscar_vendedor. Não traz status, '
            .'compras nem contatos.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => ['type' => 'string', 'description' => 'Nome, CNPJ/CPF ou código (mínimo 3 caracteres).'],
            ],
            'required' => ['termo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $resultados = array_slice($this->busca->buscar((string) ($entrada['termo'] ?? '')), 0, self::LIMITE);

        return [
            'resultados' => $resultados,
            'mensagem' => $resultados === []
                ? 'Ninguém encontrado: o cliente não está cadastrado no TOTVS nem há solicitação pendente.'
                : null,
        ];
    }
}
