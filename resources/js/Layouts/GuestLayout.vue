<script setup>
import TriangleMosaic from '@/Components/TriangleMosaic.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen">
        <div
            class="relative hidden w-full max-w-xl flex-col justify-between overflow-hidden bg-gradient-to-br from-[#1a5f96] via-[#134a7a] to-[#0f3a69] p-10 text-white md:flex"
        >
            <TriangleMosaic />

            <Link href="/" class="relative z-10">
                <img
                    src="/images/autopel-logo-white.png"
                    alt="Autopel"
                    class="h-12 w-auto"
                />
            </Link>

            <div class="relative z-10 max-w-sm">
                <span class="block text-[11px] font-medium uppercase tracking-[0.3em] text-sky-200/90">
                    PALMA CRM v2
                </span>
                <h1 class="mt-3 text-4xl font-bold">Olá!</h1>
                <p class="mt-2 text-xl text-sky-100">Seja bem-vindo(a) de volta.</p>
                <p class="mt-4 max-w-xs text-sm leading-relaxed text-sky-200/80">
                    Entre para continuar acessando a plataforma de inteligência comercial da Autopel.
                </p>
            </div>
        </div>

        <div class="flex w-full flex-1 flex-col items-center justify-center bg-white px-6 py-12">
            <div class="w-full sm:max-w-md">
                <slot />
            </div>
        </div>
    </div>
</template>
