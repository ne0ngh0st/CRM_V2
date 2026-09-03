<script setup>
import { computed, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import VisaoSupervisorToggle from '@/Components/VisaoSupervisorToggle.vue';
import SimulacaoBanner from '@/Components/SimulacaoBanner.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const page = usePage();
const isGestor = computed(() =>
    (page.props.auth?.roles ?? []).some((r) => ['admin', 'diretor', 'supervisor'].includes(r)),
);
const isAssistente = computed(() => (page.props.auth?.roles ?? []).includes('assistente'));
const visaoGestorAtiva = computed(() =>
    route().current('equipe.*')
    || route().current('visao-gestor.*')
    || route().current('metas.*'),
);
const pedidosAtivo = computed(() => route().current('pedidos.*'));
const carteiraAtiva = computed(() => route().current('carteira.*') || route().current('leads.*'));
const catalogoAtivo = computed(() =>
    route().current('tabela-precos.*') || route().current('catalogo-facas.*'),
);
</script>

<template>
    <div>
        <div class="min-h-screen bg-zinc-100">
            <!-- Antes do nav: fica no topo de QUALQUER página que use este layout. -->
            <SimulacaoBanner />

            <nav class="bg-black">
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-[1800px] px-3 sm:px-4 lg:px-6">
                    <div class="flex h-16 justify-between">
                        <div class="flex min-w-0">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <img
                                        src="/images/autopel-logo-white.png"
                                        alt="Autopel"
                                        class="h-8 w-auto"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden sm:-my-px sm:ms-8 sm:flex sm:items-stretch sm:gap-x-6">
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                    prefetch="hover"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('dashboard') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Início
                                </NavLink>

                                <!-- Bloco gestor -->
                                <div
                                    v-if="isGestor"
                                    class="relative inline-flex items-center"
                                >
                                    <Dropdown align="left" width="56">
                                        <template #trigger>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
                                                :class="visaoGestorAtiva
                                                    ? 'border-cyan text-white'
                                                    : 'border-transparent text-white/80 hover:text-white'"
                                            >
                                                Visão Gestor
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </template>
                                        <template #content>
                                            <DropdownLink :href="route('equipe.index')" prefetch="hover">
                                                Equipe
                                            </DropdownLink>
                                            <DropdownLink :href="route('visao-gestor.index')" prefetch="hover">
                                                Observações e ligações
                                            </DropdownLink>
                                            <DropdownLink :href="route('metas.index')" prefetch="hover">
                                                Metas
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>

                                <!-- Bloco comercial: Início → Carteira → Pedidos → Orçamentos → Cadastros → Tabela -->
                                <div
                                    v-if="!isAssistente"
                                    class="relative inline-flex items-center"
                                >
                                    <Dropdown align="left" width="48">
                                        <template #trigger>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
                                                :class="carteiraAtiva
                                                    ? 'border-cyan text-white'
                                                    : 'border-transparent text-white/80 hover:text-white'"
                                            >
                                                Carteira
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </template>
                                        <template #content>
                                            <DropdownLink :href="route('carteira.index')" prefetch="hover">
                                                Clientes
                                            </DropdownLink>
                                            <DropdownLink :href="route('leads.index')" prefetch="hover">
                                                Leads
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>

                                <div
                                    v-if="!isAssistente"
                                    class="relative inline-flex items-center"
                                >
                                    <Dropdown align="left" width="48">
                                        <template #trigger>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
                                                :class="pedidosAtivo
                                                    ? 'border-cyan text-white'
                                                    : 'border-transparent text-white/80 hover:text-white'"
                                            >
                                                Pedidos
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </template>
                                        <template #content>
                                            <DropdownLink :href="route('pedidos.index')" prefetch="hover">
                                                Pedidos em aberto
                                            </DropdownLink>
                                            <DropdownLink :href="route('pedidos.emitidos')" prefetch="hover">
                                                Pedidos emitidos
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>

                                <NavLink
                                    v-if="isAssistente"
                                    :href="route('leads.index')"
                                    :active="route().current('leads.*')"
                                    prefetch="hover"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('leads.*') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Leads
                                </NavLink>

                                <NavLink
                                    :href="route('orcamentos.index')"
                                    :active="route().current('orcamentos.index')"
                                    prefetch="click"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('orcamentos.index') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Orçamentos
                                </NavLink>
                                <NavLink
                                    :href="route('cadastros.index')"
                                    :active="route().current('cadastros.*')"
                                    prefetch="click"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('cadastros.*') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Cadastros
                                </NavLink>
                                <div
                                    class="relative inline-flex items-center"
                                >
                                    <Dropdown align="left" width="48">
                                        <template #trigger>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none"
                                                :class="catalogoAtivo
                                                    ? 'border-cyan text-white'
                                                    : 'border-transparent text-white/80 hover:text-white'"
                                            >
                                                Catálogo
                                                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </template>
                                        <template #content>
                                            <DropdownLink :href="route('tabela-precos.index')" prefetch="hover">
                                                Tabela de Preços
                                            </DropdownLink>
                                            <DropdownLink :href="route('catalogo-facas.index')" prefetch="hover">
                                                Catálogo de Facas
                                            </DropdownLink>
                                        </template>
                                    </Dropdown>
                                </div>
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

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
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
                                        <DropdownLink :href="route('profile.edit')">
                                            Perfil
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Sair
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="showingNavigationDropdown = !showingNavigationDropdown"
                                class="inline-flex items-center justify-center rounded-md p-2 text-white/70 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white focus:outline-none"
                            >
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex': !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex': showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Início
                        </ResponsiveNavLink>

                        <template v-if="isGestor">
                            <div class="px-4 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wide text-white/40">
                                Visão Gestor
                            </div>
                            <ResponsiveNavLink
                                :href="route('equipe.index')"
                                :active="route().current('equipe.*')"
                            >
                                Equipe
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('visao-gestor.index')"
                                :active="route().current('visao-gestor.*')"
                            >
                                Observações e ligações
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('metas.index')"
                                :active="route().current('metas.*')"
                            >
                                Metas
                            </ResponsiveNavLink>
                        </template>

                        <template v-if="!isAssistente">
                            <div class="px-4 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wide text-white/40">
                                Carteira
                            </div>
                            <ResponsiveNavLink
                                :href="route('carteira.index')"
                                :active="route().current('carteira.*')"
                            >
                                Clientes
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('leads.index')"
                                :active="route().current('leads.*')"
                            >
                                Leads
                            </ResponsiveNavLink>
                            <div class="px-4 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wide text-white/40">
                                Pedidos
                            </div>
                            <ResponsiveNavLink
                                :href="route('pedidos.index')"
                                :active="route().current('pedidos.index')"
                            >
                                Pedidos em aberto
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('pedidos.emitidos')"
                                :active="route().current('pedidos.emitidos')"
                            >
                                Pedidos emitidos
                            </ResponsiveNavLink>
                        </template>
                        <ResponsiveNavLink
                            v-if="isAssistente"
                            :href="route('leads.index')"
                            :active="route().current('leads.*')"
                        >
                            Leads
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('orcamentos.index')"
                            :active="route().current('orcamentos.index')"
                        >
                            Orçamentos
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('cadastros.index')"
                            :active="route().current('cadastros.*')"
                        >
                            Cadastros
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('tabela-precos.index')"
                            :active="route().current('tabela-precos.*')"
                        >
                            Catálogo · Tabela de Preços
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('catalogo-facas.index')"
                            :active="route().current('catalogo-facas.*')"
                        >
                            Catálogo · Facas
                        </ResponsiveNavLink>
                    </div>

                    <div class="border-t border-white/10 pb-1 pt-4">
                        <div class="flex items-center justify-between px-4">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-white/20 bg-white/10"
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
                                <div class="min-w-0">
                                    <div class="truncate text-base font-medium text-white">
                                        {{ $page.props.auth.user.display_name || $page.props.auth.user.name }}
                                    </div>
                                    <div class="truncate text-sm font-medium text-white/60">
                                        {{ $page.props.auth.user.email }}
                                    </div>
                                </div>
                            </div>

                            <VisaoSupervisorToggle />
                            <NotificationBell />
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Perfil
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Sair
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <header
                v-if="$slots.header"
                class="bg-white shadow-sm"
            >
                <div class="mx-auto max-w-[1800px] px-3 py-6 sm:px-4 lg:px-6">
                    <slot name="header" />
                </div>
            </header>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
