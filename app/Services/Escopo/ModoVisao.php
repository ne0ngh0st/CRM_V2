<?php

namespace App\Services\Escopo;

use Illuminate\Contracts\Session\Session;

/**
 * Em que modo o supervisor está olhando o sistema: EQUIPE ou MINHA CARTEIRA.
 *
 * Na Autopel o supervisor também vende — é exceção da casa, não prática de mercado, e o
 * sistema não modelava isso. O escopo dele resolvia apenas a equipe, então os 9.387
 * clientes (10,2% da base) que são carteira PESSOAL de supervisor não eram vistos por
 * ninguém nesse perfil.
 *
 * ⚠️ O MODO É LIDO DENTRO DO RESOLVER, nunca passado de controller em controller.
 * `DashboardScopeResolver::resolve()` é chamado em 18 pontos de 8 controllers; enfiar um
 * parâmetro em cada chamada é exatamente o que o legado fez com
 * `supervisor_apenas_proprios` — threaded à mão por ~15 arquivos, e o resultado foi
 * inconsistência entre telas (`faturamento_where_por_perfil.php` incluía o próprio
 * código; `grafico_status_clientes.php` não). Regra de ouro nº 8.
 *
 * ⚠️ CUSTO POR REQUEST: ZERO QUERY. Mesmo desenho da simulação de usuário — o estado mora
 * na sessão, que já é carregada de qualquer forma. Se alguém trocar isto por uma consulta
 * ao banco, vira uma query a mais em toda página de todo usuário (Regra de ouro nº 9).
 */
class ModoVisao
{
    public const CHAVE_SESSAO = 'visao_modo';

    public const EQUIPE = 'equipe';

    public const PESSOAL = 'pessoal';

    /** @var list<string> */
    public const MODOS = [self::EQUIPE, self::PESSOAL];

    public function __construct(private readonly Session $sessao) {}

    /**
     * ⚠️ Fora de contexto HTTP não há sessão com este valor — fila, `cache:aquecer`,
     * comandos de console. Nesses casos o modo é sempre EQUIPE, e tem que ser: o job de
     * aquecimento monta chaves de cache a partir do escopo resolvido, e se ele "herdasse"
     * o modo pessoal de alguém aqueceria uma chave que nenhuma requisição procura.
     */
    public function atual(): string
    {
        $modo = $this->sessao->get(self::CHAVE_SESSAO, self::EQUIPE);

        return in_array($modo, self::MODOS, true) ? $modo : self::EQUIPE;
    }

    public function pessoal(): bool
    {
        return $this->atual() === self::PESSOAL;
    }

    public function definir(string $modo): void
    {
        if (in_array($modo, self::MODOS, true)) {
            $this->sessao->put(self::CHAVE_SESSAO, $modo);
        }
    }
}
