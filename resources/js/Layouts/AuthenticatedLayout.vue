<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div>
        <div class="min-h-screen bg-zinc-100">
            <nav class="bg-black">
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-[1800px] px-3 sm:px-4 lg:px-6">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
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
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('dashboard') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    v-if="$page.props.auth.roles.some((r) => ['admin', 'diretor', 'supervisor'].includes(r))"
                                    :href="route('equipe.index')"
                                    :active="route().current('equipe.index')"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('equipe.index') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Equipe
                                </NavLink>
                                <NavLink
                                    v-if="!$page.props.auth.roles.includes('assistente')"
                                    :href="route('pedidos.index')"
                                    :active="route().current('pedidos.index')"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('pedidos.index') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Pedidos
                                </NavLink>
                                <NavLink
                                    v-if="!$page.props.auth.roles.includes('assistente')"
                                    :href="route('orcamentos.index')"
                                    :active="route().current('orcamentos.index')"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('orcamentos.index') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Orçamentos
                                </NavLink>
                                <NavLink
                                    v-if="!$page.props.auth.roles.includes('assistente')"
                                    :href="route('carteira.index')"
                                    :active="route().current('carteira.index')"
                                    class="!text-white/80 hover:!text-white"
                                    :class="route().current('carteira.index') ? '!border-cyan !text-white' : '!border-transparent'"
                                >
                                    Carteira
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent px-3 py-2 text-sm font-medium leading-4 text-white/80 transition duration-150 ease-in-out hover:text-white focus:outline-none"
                                            >
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
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
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
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-white/70 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
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
                            Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="$page.props.auth.roles.some((r) => ['admin', 'diretor', 'supervisor'].includes(r))"
                            :href="route('equipe.index')"
                            :active="route().current('equipe.index')"
                        >
                            Equipe
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="!$page.props.auth.roles.includes('assistente')"
                            :href="route('pedidos.index')"
                            :active="route().current('pedidos.index')"
                        >
                            Pedidos
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="!$page.props.auth.roles.includes('assistente')"
                            :href="route('orcamentos.index')"
                            :active="route().current('orcamentos.index')"
                        >
                            Orçamentos
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="!$page.props.auth.roles.includes('assistente')"
                            :href="route('carteira.index')"
                            :active="route().current('carteira.index')"
                        >
                            Carteira
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div class="border-t border-white/10 pb-1 pt-4">
                        <div class="px-4">
                            <div class="text-base font-medium text-white">
                                {{ $page.props.auth.user.display_name || $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-white/60">
                                {{ $page.props.auth.user.email }}
                            </div>
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

            <!-- Page Heading -->
            <header
                class="bg-white shadow-sm"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-[1800px] px-3 py-6 sm:px-4 lg:px-6">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
