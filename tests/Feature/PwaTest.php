<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PWA: o PALMA instalável na tela inicial do celular.
 *
 * Não usa RefreshDatabase — GET /login não toca tabela, e os outros asserts leem
 * arquivo em disco. Rodar isto não disputa o `palma_v2_test` com outra sessão.
 *
 * ⚠️ O que este teste NÃO alcança: o Chrome de fato oferecer o botão Instalar. Isso
 * exige HTTPS (ou localhost), o SW com handler de fetch, e ícones 192/512. Os
 * arquivos e as tags no HTML são o que dá para travar aqui; o resto é conferir no
 * aparelho depois do deploy.
 */
class PwaTest extends TestCase
{
    public function test_o_html_aponta_pro_manifesto_e_pro_icone_ios(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/manifest.json', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('/images/pwa/apple-touch-icon.png', false)
            ->assertSee('/images/pwa/favicon-32.png', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('viewport-fit=cover', false);
    }

    public function test_o_manifesto_e_instalavel(): void
    {
        $caminho = public_path('manifest.json');
        $this->assertFileExists($caminho);

        $manifesto = json_decode((string) file_get_contents($caminho), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('PALMA', $manifesto['short_name']);
        $this->assertSame('standalone', $manifesto['display']);
        $this->assertSame('/dashboard', $manifesto['start_url']);
        $this->assertNotEmpty($manifesto['icons']);

        foreach ($manifesto['icons'] as $icone) {
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')));
        }
    }

    public function test_o_service_worker_nao_cacheia_pagina(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("addEventListener('fetch'", $sw);
        $this->assertStringContainsString("/build/assets/", $sw);
        $this->assertStringNotContainsString('cache.addAll', $sw);
        $this->assertStringNotContainsString("caches.match(event.request)", $sw);
    }
}
