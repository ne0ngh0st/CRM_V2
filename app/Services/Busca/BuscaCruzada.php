<?php

namespace App\Services\Busca;

use App\Models\Cliente;
use App\Models\Lead;

/**
 * "Quantos da OUTRA página batem com esta busca?" — o aviso cruzado entre Carteira e Leads.
 *
 * Existe porque o vendedor busca "Kntt" na Carteira, não acha, e conclui que não existe,
 * quando o Kntt é um lead. As duas páginas continuam separadas; o aviso só aponta para a
 * outra quando ela tem resultado.
 *
 * 🚨 O número tem que ser EXATAMENTE o total que a outra página mostra ao abrir o link
 * (`?busca=…` e nada mais). Por isso:
 *  - a regra de busca é o escopo `busca()` do próprio model, o mesmo que a tela usa;
 *  - clientes contam por `cod_cliente`, não por filial, porque a Carteira abre agrupada
 *    por padrão — o mesmo `distinct()->count()` de `CarteiraController::totalAgrupado()`;
 *  - leads passam por `visivel()`, como a tela de Leads.
 * Há teste comparando os dois números. Filtro novo que a tela passe a aplicar por PADRÃO
 * (sem estar na URL) tem que entrar aqui também, senão o aviso promete um número e o
 * clique mostra outro.
 *
 * Sem cache de propósito: roda numa requisição à parte, DEPOIS que a lista já desenhou,
 * então não pesa na primeira pintura (Regra de ouro nº 9) — e um termo de busca raramente
 * se repete dentro de uma janela de cache.
 */
class BuscaCruzada
{
    /** Menos que isso casa meia base e o aviso vira ruído. */
    public const MINIMO_CARACTERES = 3;

    /** @param  array<string>|null  $codVendedores  null = sem restrição, como no DashboardScopeResolver */
    public function contarClientes(?array $codVendedores, string $termo): int
    {
        $query = Cliente::query()->busca($termo);

        if ($codVendedores !== null) {
            $query->whereIn('clientes.cod_vendedor', $codVendedores);
        }

        return $query->distinct()->count('clientes.cod_cliente');
    }

    /** @param  array<string>|null  $codVendedores */
    public function contarLeads(?array $codVendedores, string $termo): int
    {
        $query = Lead::query()->visivel()->busca($termo);

        if ($codVendedores !== null) {
            $query->whereIn('cod_vendedor', $codVendedores);
        }

        return $query->count();
    }
}
