<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use Illuminate\Support\Collection;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\AvisoCodigoGrupo;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\GrupoNaResposta;

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
            .'fantasia), CNPJ/CPF ou código de CLIENTE do TOTVS (cod_cliente, ex.: 215371). '
            .'Se o nome exato não existir, procura pelas palavras em qualquer ordem ("C6 Bank" '
            .'acha "BANCO C6") e avisa que é aproximado. Devolve até 10, com o id para usar em resumo_cliente, numeros_do_cliente e '
            .'consultar_orcamento, e o grupo de vendas de cada um. Código de GRUPO (000258, '
            .'"grupo nr") NÃO entra aqui — isso é consultar_grupo. Se não achar, o cliente '
            .'pode ser de outra carteira: aí use quem_cuida_do_cliente.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => ['type' => 'string', 'description' => 'Nome, CNPJ/CPF ou código de cliente (não código de grupo). Mínimo 3 caracteres.'],
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
        $aproximado = false;

        // "C6 Bank" não casa com "BANCO C6" por começo de nome: tenta pelas palavras.
        if ($clientes->isEmpty()) {
            $clientes = $this->busca->clientesPorPalavras($termo, $codigos, self::LIMITE);
            $aproximado = $clientes->isNotEmpty();
        }
        $nomesDeGrupo = GrupoNaResposta::nomes($clientes->pluck('cod_grupo'));
        $saida = [
            'clientes' => $clientes->map(fn (Cliente $c) => [
                'id' => $c->id,
                'razao_social' => $c->razao_social,
                'nome_fantasia' => $c->nome_fantasia,
                'cnpj' => $c->cnpj,
                'codigo_totvs' => $c->codigoTotvs(),
                'grupo' => GrupoNaResposta::de($c->cod_grupo, $nomesDeGrupo),
            ])->values()->all(),
            'mensagem' => match (true) {
                $clientes->isEmpty() => 'Nenhum cliente com esse termo na carteira do usuário.',
                $aproximado => 'Resultado aproximado: nenhum nome começa ou contém exatamente esse termo. Estes casam com PALAVRAS dele, em outra ordem: confirme com o usuário se é um deles antes de seguir.',
                default => null,
            },
        ];
        $aviso = $this->avisoDeGrupo($termo, $clientes);

        if ($aviso !== null) {
            $saida['grupo_com_este_numero'] = $aviso;
        }

        return $saida;
    }

    /**
     * @param  Collection<int, Cliente>  $clientes
     * @return array{codigo: string, nome: string, aviso: string}|null
     */
    private function avisoDeGrupo(string $termo, Collection $clientes): ?array
    {
        $grupo = AvisoCodigoGrupo::paraTermo($termo);

        if ($grupo === null) {
            return null;
        }

        $saoDoGrupo = $clientes->isNotEmpty() && $clientes->every(
            fn (Cliente $c) => (string) $c->cod_grupo === $grupo['codigo'],
        );

        $aviso = $saoDoGrupo
            ? "Estes clientes pertencem ao grupo {$grupo['codigo']} ({$grupo['nome']}). Para as caixas do grupo inteiro, chame consultar_grupo."
            : "Este número é o grupo de vendas {$grupo['codigo']} ({$grupo['nome']}), não um código de cliente. "
                .'Para caixas ou para o grupo, chame consultar_grupo. A lista abaixo, se houver, é outra empresa.';

        return $grupo + ['aviso' => $aviso];
    }
}
