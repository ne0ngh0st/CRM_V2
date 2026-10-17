<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Services\Receita\SituacaoCadastral;

/**
 * O veredito da página Maiores redes sobre a rede NO MERCADO: ela existe e está operando?
 * É pesquisa de mercado (Tony, 2026-10-17), não a saúde do nosso cadastro: o Burger King
 * está vivo e compra da gente mesmo com o CNPJ antigo (BGK do Brasil) baixado, e a Raia tem
 * 102 filiais nossas fechadas em 4.062 — rotina de rede grande. Por isso as filiais dos
 * nossos clientes NÃO entram aqui.
 *
 * - **Ativa**: a empresa dona da rede está ATIVA na Receita, ou há lojas ativas com o nome
 *   da rede (franquia, que não tem CNPJ único; ou a marca seguiu com outra empresa).
 * - **Rede fechada**: só achou empresa baixada/inapta e nenhuma loja ativa com o nome.
 * - **Sem informação**: nada disso se sabe.
 *
 * Gravado em `redes_mercado.situacao_rede` por `MaioresRedes::recalcular()` (a tela filtra e
 * conta pelo banco). Regra única: a tela, o filtro e o Excel leem daqui.
 */
final class SituacaoDaRede
{
    public const ATIVA = 'ativa';

    public const FECHADA = 'fechada';

    public const SEM_INFORMACAO = 'sem_informacao';

    public const ROTULOS = [
        self::ATIVA => 'Ativa',
        self::FECHADA => 'Rede fechada',
        self::SEM_INFORMACAO => 'Sem informação',
    ];

    /** As que pedem atenção — viram filtro e tile na tela. */
    public const DE_ATENCAO = [self::FECHADA];

    /** Quantas lojas ativas com o nome provam que a rede existe (abaixo disso pode ser homônimo). */
    public const MINIMO_LOJAS_COM_NOME = 3;

    public static function de(?string $cnpj, ?string $situacaoDoCnpj, ?int $lojasComNomeAtivas): string
    {
        if (($lojasComNomeAtivas ?? 0) >= self::MINIMO_LOJAS_COM_NOME || ($cnpj !== null && $situacaoDoCnpj === SituacaoCadastral::ATIVA)) {
            return self::ATIVA;
        }

        if ($cnpj !== null && SituacaoCadastral::irregular($situacaoDoCnpj) && ! $lojasComNomeAtivas) {
            return self::FECHADA;
        }

        return self::SEM_INFORMACAO;
    }
}
