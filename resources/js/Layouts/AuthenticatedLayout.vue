<script setup>
/**
 * O shell de toda página autenticada.
 *
 * ⚠️ A ESTRUTURA DO MENU NÃO MORA AQUI desde 2026-09-15 — está em
 * `constants/navegacao.js`. Antes era escrita duas vezes NESTE arquivo (nav desktop e
 * hambúrguer) e as duas já divergiam: o link admin "Atualização de dados" existia só no
 * desktop, então nenhum admin alcançava aquela tela pelo celular. Com a barra inferior
 * seria uma terceira cópia. Item de menu novo entra LÁ, não aqui.
 *
 * ⚠️ Duas navegações, uma de cada vez: nav horizontal a partir de `sm`, `BarraInferior` +
 * `GavetaMobile` abaixo disso. O hambúrguer não existe mais — o que era o menu inteiro
 * virou o botão "Mais" da barra.
 */
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import VisaoSupervisorToggle from '@/Components/VisaoSupervisorToggle.vue';
import SimulacaoBanner from '@/Components/SimulacaoBanner.vue';
import BarraInferior from '@/Components/Navegacao/BarraInferior.vue';
import GavetaMobile from '@/Components/Navegacao/GavetaMobile.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { estaAtivo, menuPrincipal, menuUsuario } from '@/constants/navegacao';

const gavetaAberta = ref(false);

const page = usePage();
const papeis = computed(() => page.props.auth?.roles ?? []);

/**
 * Um objeto só com os papéis, resolvido uma vez e passado adiante.
 *
 * ⚠️ É o contrato de `constants/navegacao.js`. Os três consumidores (este nav, a barra e a
 * gaveta) recebem o MESMO objeto: se cada um decidisse "sou gestor?" por conta própria, o
 * menu voltaria a poder diferir entre desktop e celular — que é exatamente o defeito que a
 * extração consertou.
 */
const perfil = computed(() => ({
    isGestor: papeis.value.some((r) => ['admin', 'diretor', 'supervisor'].includes(r)),
    isDiretor: papeis.value.some((r) => ['admin', 'diretor'].includes(r)),
    isAssistente: papeis.value.includes('assistente'),
    isAdmin: papeis.value.includes('admin'),
}));

/*
 * ⚠️ Depende de `page.url` de propósito, mesmo sem usar o valor: `route().current()` lê a
 * URL do navegador, que não é reativa. Hoje o layout é remontado em cada visita do Inertia
 * e o cálculo aconteceria de novo sozinho, mas o layout persistente está na lista de coisas
 * a fazer — e no dia em que entrar, o menu congelaria no estado da primeira página, sem
 * erro nenhum aparecer.
 */
const grupos = computed(() => {
    void page.url;

    return menuPrincipal(perfil.value).map((grupo) => ({
        ...grupo,
        ativo: estaAtivo(grupo.ativoEm),
    }));
});

const itensDoUsuario = computed(() => menuUsuario(perfil.value));
</script>

<template>
    <div>
        <div class="min-h-screen bg-zinc-100">
            <!-- Antes do nav: fica no topo de QUALQUER página que use este layout. -->
            <SimulacaoBanner />

            <nav class="bg-black">
                <div class="mx-auto max-w-[1800px] px-3 sm:px-4 lg:px-6">
                    <div class="flex h-16 justify-between">
                        <div class="flex min-w-0">
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')" class="inline-flex min-h-11 items-center">
                                    <img src="/images/autopel-logo-white.png" alt="Autopel" class="h-8 w-auto" />
                                </Link>
                            </div>

                            <!-- Navegação desktop -->
                            <div class="hidden sm:-my-px sm:ms-8 sm:flex sm:items-stretch sm:gap-x-6">
                                <template v-for="grupo in grupos" :key="grupo.chave">
                                    <div v-if="grupo.itens?.length" class="relative inline-flex items-center">
                                        <Dropdown align="left" :width="grupo.largura">
                                            <template #trigger>
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
                                                    :class="grupo.ativo
                                                        ? 'border-cyan text-white'
                                                        : 'border-transparent text-white/80 hover:text-white'"
                                                >
                                                    {{ grupo.rotulo }}
                                                    <!--
                                                        A seta era copiada em quatro gatilhos idênticos.
                                                        Com os grupos vindo de dados, ela é escrita uma vez.
                                                    -->
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </button>
                                            </template>
                                            <template #content>
                                                <DropdownLink
                                                    v-for="item in grupo.itens"
                                                    :key="item.chave"
                                                    :href="route(item.rota)"
                                                    :prefetch="item.prefetch ?? false"
                                                >
                                                    {{ item.rotulo }}
                                                </DropdownLink>
                                            </template>
                                        </Dropdown>
                                    </div>

                                    <NavLink
                                        v-else
                                        :href="route(grupo.rota)"
                                        :active="grupo.ativo"
                                        :prefetch="grupo.prefetch ?? false"
                                        class="!text-white/80 hover:!text-white"
                                        :class="grupo.ativo ? '!border-cyan !text-white' : '!border-transparent'"
                                    >
                                        {{ grupo.rotulo }}
                                    </NavLink>
                                </template>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center sm:gap-1">
                            <VisaoSupervisorToggle />
                            <NotificationBell />

                            <div class="relative ms-2">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-2 rounded-md border border-transparent px-3 py-2 text-sm font-medium leading-4 text-white/80 transition duration-150 ease-in-out hover:text-white focus:outline-none"
                                            >
                                                <span
                                                    class="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full border border-white/20 bg-white/10"
                                                >
                                                    <img
                                                        v-if="$page.props.auth.user.foto_url"
                                                        :src="$page.props.auth.user.foto_url"
                                                        alt=""
                                                        class="h-full w-full object-cover"
                                                    />
                                                    <svg
                                                        v-else
                                                        class="h-3.5 w-3.5 text-white/70"
                                                        fill="currentColor"
                                                        viewBox="0 0 24 24"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v1.2h19.2v-1.2c0-3.2-6.4-4.8-9.6-4.8z"
                                                        />
                                                    </svg>
                                                </span>
                                                {{ $page.props.auth.user.display_name || $page.props.auth.user.name }}

                                                <svg class="-me-0.5 ms-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            v-for="item in itensDoUsuario"
                                            :key="item.chave"
                                            :href="route(item.rota)"
                                        >
                                            {{ item.rotulo }}
                                        </DropdownLink>
                                        <DropdownLink :href="route('logout')" method="post" as="button">
                                            Sair
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!--
                            Topo no celular: só sino e avatar. A navegação desceu para a
                            `BarraInferior`, e o hambúrguer que ficava aqui deixou de existir —
                            manter os dois seria oferecer dois caminhos para a mesma tela, com
                            marcações de "página atual" independentes.
                        -->
                        <div class="-me-1 flex items-center gap-1 sm:hidden">
                            <NotificationBell />
                            <Link
                                :href="route('profile.edit')"
                                class="flex h-11 w-11 items-center justify-center"
                                aria-label="Perfil"
                            >
                                <span
                                    class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-white/20 bg-white/10"
                                >
                                    <img
                                        v-if="$page.props.auth.user.foto_url"
                                        :src="$page.props.auth.user.foto_url"
                                        alt=""
                                        class="h-full w-full object-cover"
                                    />
                                    <svg
                                        v-else
                                        class="h-4 w-4 text-white/70"
                                        fill="currentColor"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v1.2h19.2v-1.2c0-3.2-6.4-4.8-9.6-4.8z"
                                        />
                                    </svg>
                                </span>
                            </Link>
                        </div>
                    </div>
                </div>
            </nav>

            <header v-if="$slots.header" class="bg-white shadow-sm">
                <div class="mx-auto max-w-[1800px] px-3 py-6 sm:px-4 lg:px-6">
                    <slot name="header" />
                </div>
            </header>

            <!--
                ⚠️ `pb-20 sm:pb-0` fica AQUI e não nas páginas: a barra inferior é `fixed` e
                cobriria os últimos ~64px do conteúdo (o botão de salvar de um formulário, a
                última linha de uma tabela). O container `max-w-[1800px]` está copiado em 18
                arquivos, e pôr a folga em cada um seria a 19ª chance de esquecer.
            -->
            <main class="pb-20 sm:pb-0">
                <slot />
            </main>
        </div>

        <BarraInferior
            :perfil="perfil"
            :gaveta-aberta="gavetaAberta"
            @abrir-mais="gavetaAberta = !gavetaAberta"
        />
        <GavetaMobile :show="gavetaAberta" :perfil="perfil" @close="gavetaAberta = false" />
    </div>
</template>
