<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown → HTML pronto para `v-html`. Único lugar do sistema que faz isso.
 *
 * ⚠️ `html_input => strip` e `allow_unsafe_links => false` SÃO a segurança de quem usa:
 * a intranet (texto de gestor lido por ~200 pessoas) e o assistente de IA (texto gerado
 * por modelo, que pode ecoar algo vindo de fora). Um `<script>` que passasse por aqui
 * rodaria na sessão de quem lê. Renderizar no servidor mantém a regra num lugar só e
 * dispensa biblioteca de markdown no bundle.
 */
final class Markdown
{
    public static function seguro(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
