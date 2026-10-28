<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Services\Cadastros\BuscaTitularidade;
use App\Services\Ia\AvisoCodigoGrupo;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\GrupoNaResposta;

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
            .'CNPJ/CPF ou código de cliente (com ou sem a loja: 015076 ou 015076/0001). Também '
            .'devolve o grupo de vendas dessa empresa e mostra solicitações de cadastro ainda '
            .'pendentes. Use para "esse CNPJ é de alguém?". "Este grupo" depois disso é o campo '
            .'grupo — as caixas do grupo são consultar_grupo, nunca este código como cliente. '
            .'Não serve para achar uma pessoa da Autopel (buscar_vendedor). Não traz status, '
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
        $termo = (string) ($entrada['termo'] ?? '');
        $resultados = array_slice($this->busca->buscar($termo), 0, self::LIMITE);
        $aproximado = false;

        if ($resultados === []) {
            $resultados = array_slice($this->busca->buscarPorPalavras($termo), 0, self::LIMITE);
            $aproximado = $resultados !== [];
        }

        $saida = [
            'resultados' => $this->comGrupo($resultados),
            'mensagem' => match (true) {
                $resultados === [] => 'Ninguém encontrado: o cliente não está cadastrado no TOTVS nem há solicitação pendente.',
                $aproximado => 'Resultado aproximado: nenhum nome começa ou contém exatamente esse termo. Estes casam com PALAVRAS dele, em outra ordem: confirme com o usuário se é um deles.',
                default => null,
            },
        ];
        $aviso = AvisoCodigoGrupo::paraTermo($termo);

        if ($aviso !== null) {
            $saida['grupo_com_este_numero'] = $aviso;
        }

        return $saida;
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     * @return list<array<string, mixed>>
     */
    private function comGrupo(array $resultados): array
    {
        $codigos = collect($resultados)->pluck('codCliente')->filter()->unique()->values();

        if ($codigos->isEmpty()) {
            return $resultados;
        }

        $porFilial = Cliente::query()
            ->whereIn('cod_cliente', $codigos)
            ->get(['cod_cliente', 'loja', 'cod_grupo'])
            ->keyBy(fn (Cliente $c) => $c->cod_cliente.'|'.$c->loja);
        $nomes = GrupoNaResposta::nomes($porFilial->pluck('cod_grupo'));

        return array_map(function (array $linha) use ($porFilial, $nomes) {
            $filial = $porFilial[($linha['codCliente'] ?? '').'|'.($linha['loja'] ?? '')] ?? null;
            $linha['grupo'] = $filial ? GrupoNaResposta::de($filial->cod_grupo, $nomes) : null;

            return $linha;
        }, $resultados);
    }
}
