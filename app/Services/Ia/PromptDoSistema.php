<?php

namespace App\Services\Ia;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * O que vai no `system` e o bloco de contexto de cada pergunta.
 *
 * ⚠️ O `system` tem que ser BYTE A BYTE igual entre requisições: ele fica em cache na
 * Anthropic (custa ~5% do preço na leitura) e qualquer coisa variável aqui — data,
 * nome do usuário — invalidaria o cache de todo mundo a cada pergunta. O que varia vai
 * no bloco <contexto> da própria pergunta ({@see contexto()}).
 *
 * Os textos moram em resources/ia/ para serem revisados como texto, não como código.
 */
final class PromptDoSistema
{
    /** @return list<array<string, mixed>> */
    public function sistema(): array
    {
        return [
            ['type' => 'text', 'text' => trim((string) file_get_contents(resource_path('ia/instrucoes.md')))],
            [
                'type' => 'text',
                'text' => trim((string) file_get_contents(resource_path('ia/manual.md'))),
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }

    public function contexto(User $user, ?string $tela, CarbonInterface $agora): string
    {
        $perfil = $user->getRoleNames()->first() ?? 'sem perfil';
        $codigo = $user->vendedorPerfil?->cod_vendedor;

        return "<contexto>\n"
            .'Hoje: '.$agora->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY, HH:mm')."\n"
            .'Usuário: '.($user->display_name ?: $user->name).", perfil {$perfil}"
            .($codigo ? ", código de vendedor {$codigo}" : ', sem código de vendedor')."\n"
            .'Tela aberta: '.($tela ?: 'não informada')."\n"
            .'</contexto>';
    }
}
