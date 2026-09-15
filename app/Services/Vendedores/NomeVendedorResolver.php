<?php

namespace App\Services\Vendedores;

use App\Models\VendedorPerfil;
use App\Models\VendedorTotvs;
use Illuminate\Support\Collection;

/**
 * Código de vendedor -> nome que aparece na tela.
 *
 * Existe porque a MESMA decisão estava copiada em nove lugares (Carteira listagem e
 * ficha, Leads, Pedidos abertos e emitidos, Equipe e quatro Excel), sempre na forma
 * `VendedorPerfil::whereIn(...)->mapWithKeys(display_name ?: name)` com `?? $codigo` no
 * fim. Regra de ouro nº 8: nove cópias é como uma tela passa a responder diferente da
 * outra sem ninguém ter decidido isso.
 *
 * A ORDEM É A REGRA, e cada degrau existe por um motivo:
 *
 *   1. quem tem conta no CRM  → `display_name ?: name`, o nome pelo qual a empresa
 *      chama a pessoa ("RAIMUNDO"). Continua vencendo, então nada muda para os clientes
 *      que já exibiam nome.
 *   2. `vendedores_totvs`     → o nome que o TOTVS dá ao código. Cobre ex-funcionário,
 *      gente de licitação/SAC (fora de escopo, Regra nº 2) e os baldes do próprio TOTVS
 *      ("INATIVOS", "CLIENTE SEM COMPRA"). São 320 dos 444 códigos da base.
 *   3. o próprio código       → só quando o TOTVS nunca emitiu nota por aquele código.
 *      Medido em 15/09/2026: 8 clientes de 92.209, contra os 25.208 de antes do degrau 2.
 *
 * ⚠️ O degrau 3 não é decoração: apagá-lo faria a coluna aparecer VAZIA, que é pior que
 * o código — o código pelo menos é procurável no TOTVS.
 *
 * ⚠️ `cod_vendedor` NÃO é único em `vendedor_perfis` (há contas compartilhando código).
 * Quando duas contas dividem o mesmo código, vence a última — comportamento herdado das
 * nove cópias e mantido de propósito: aqui a pergunta é "que nome escrevo nesta célula?",
 * e quem precisa da lista inteira é a busca de titularidade, que resolve isso por conta
 * própria em `BuscaTitularidade::responsaveisPorCodigo()`.
 */
class NomeVendedorResolver
{
    /**
     * @param  iterable<int, string|null>  $codigos
     * @return array<string, string> todo código pedido tem entrada; o valor nunca é nulo
     */
    public function porCodigo(iterable $codigos): array
    {
        $codigos = Collection::make($codigos)
            ->map(fn ($codigo) => trim((string) $codigo))
            ->filter(fn (string $codigo) => $codigo !== '')
            ->unique()
            ->values();

        if ($codigos->isEmpty()) {
            return [];
        }

        $nomes = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codigos)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name])
            /*
             * ⚠️ Descarta o nome VAZIO, e é isso que o `filter()` faz aqui de verdade —
             * perfil sem usuário não existe (`vendedor_perfis.user_id` é ON DELETE
             * CASCADE), então essa não é a hipótese. Conta com `display_name` nulo e
             * `name` em branco existe, e sem este filtro ela vence o degrau 2 com uma
             * string vazia: a célula sai EM BRANCO, que é o pior dos três resultados
             * possíveis. Descoberto por mutação — a primeira versão deste comentário
             * afirmava a hipótese errada e o teste que a cobria passava com o filtro
             * removido.
             */
            ->filter()
            ->all();

        $semConta = $codigos->reject(fn (string $codigo) => isset($nomes[$codigo]));

        if ($semConta->isNotEmpty()) {
            $nomes += VendedorTotvs::query()
                ->whereIn('codigo', $semConta)
                ->pluck('nome', 'codigo')
                ->all();
        }

        foreach ($codigos as $codigo) {
            $nomes[$codigo] ??= $codigo;
        }

        return $nomes;
    }

    /**
     * Só o degrau 2 — o nome que o TOTVS dá a cada código, sem o do CRM por cima e sem
     * cair para o código cru.
     *
     * Existe para quem já resolveu o CRM por conta própria e precisa saber se o que
     * sobrou tem nome ou não: é o caso de `BuscaTitularidade`, que monta uma LISTA de
     * responsáveis (código compartilhado por mais de uma conta) e ali "não achei nome"
     * tem que continuar distinguível de "achei" — a tela escreve "sem responsável".
     *
     * @param  iterable<int, string|null>  $codigos
     * @return array<string, string> só os códigos que o TOTVS conhece
     */
    public function doTotvs(iterable $codigos): array
    {
        $codigos = Collection::make($codigos)
            ->map(fn ($codigo) => trim((string) $codigo))
            ->filter(fn (string $codigo) => $codigo !== '')
            ->unique();

        if ($codigos->isEmpty()) {
            return [];
        }

        return VendedorTotvs::query()->whereIn('codigo', $codigos)->pluck('nome', 'codigo')->all();
    }

    /**
     * O mapa INTEIRO, para quem não sabe de antemão quais códigos vai encontrar — os
     * exports percorrem a carteira em chunks e não têm a lista de códigos na mão.
     *
     * ⚠️ Aqui o degrau 3 (cair para o próprio código) fica com quem chama, porque não há
     * como pré-computar entrada para um código que ainda não apareceu. Use sempre
     * `->get($codigo, $codigo)`: com `->get($codigo)` seco, os poucos códigos que o TOTVS
     * nunca emitiu passam a sair como célula VAZIA no Excel.
     *
     * São ~570 linhas somadas (134 perfis + 439 do TOTVS), carregadas uma vez por export.
     *
     * @return Collection<string, string>
     */
    public function todos(): Collection
    {
        $doTotvs = VendedorTotvs::query()->pluck('nome', 'codigo');

        $doCrm = VendedorPerfil::query()
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name])
            ->filter();

        // `merge` com o CRM por último: quem tem conta aqui vence o nome do TOTVS.
        return $doTotvs->merge($doCrm);
    }

    /** Um código só — para a ficha do cliente, que não tem lista. */
    public function para(?string $codigo): ?string
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }

        return $this->porCodigo([$codigo])[trim($codigo)] ?? $codigo;
    }
}
