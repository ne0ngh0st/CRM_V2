<?php

namespace Database\Seeders;

use App\Models\MarketingWpFormulario;
use Illuminate\Database\Seeder;

/**
 * Garante que o formulário `*` (dono padrão dos leads do site) exista.
 *
 * ⚠️ Por que existe, se a migration já insere a linha: migration roda uma vez
 * só. Se alguém apagar ou desativar a linha `*`, todo lead do site passa a
 * nascer com `cod_vendedor` nulo — e LeadController::scopeQuery filtra por
 * `whereIn('cod_vendedor', …)`, então o lead existe no banco e NENHUM vendedor
 * o vê. Entra e some, sem erro em lugar nenhum.
 *
 * Este seeder é a rede: roda junto do db:seed da restauração e recria o
 * fallback. Nunca sobrescreve o cod_vendedor de uma linha existente — trocar o
 * dono é decisão comercial, feita no banco, não aqui.
 */
class MarketingWpFormularioSeeder extends Seeder
{
    /** Vendedor que recebe lead do site sem regra própria. */
    private const COD_VENDEDOR_PADRAO = '010617';

    public function run(): void
    {
        MarketingWpFormulario::query()->firstOrCreate(
            ['identificador' => MarketingWpFormulario::IDENTIFICADOR_PADRAO],
            [
                'nome' => 'Padrão (todo form sem regra própria)',
                'cod_vendedor' => self::COD_VENDEDOR_PADRAO,
                'ativo' => true,
            ],
        );
    }
}
