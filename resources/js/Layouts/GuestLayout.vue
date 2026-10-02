<script setup>
import TriangleMosaic from '@/Components/TriangleMosaic.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <!--
        Um painel só para notebook e celular. No celular ele fica em cima do formulário e
        mostra SÓ o logo (decisão do Tony: o texto de boas-vindas é coisa do notebook); no
        notebook vira a coluna da esquerda com logo e boas-vindas.
    -->
    <div class="flex min-h-screen flex-col md:flex-row">
        <div
            class="relative flex w-full flex-col justify-between gap-10 overflow-hidden bg-gradient-to-br from-[#1a5f96] via-[#134a7a] to-[#0f3a69] px-6 py-8 text-white md:max-w-xl md:p-10"
        >
            <TriangleMosaic />

            <Link href="/" class="relative z-10 self-start">
                <img
                    src="/images/palma/palma-logo-branco.svg"
                    alt="PALMA por Autopel"
                    class="h-12 w-auto md:h-16"
                />
            </Link>

            <div class="relative z-10 hidden max-w-sm md:block">
                <span class="block text-[11px] font-medium uppercase tracking-[0.3em] text-sky-200/90">
                    CRM comercial da Autopel
                </span>
                <h1 class="mt-3 text-4xl font-bold">Olá!</h1>
                <p class="mt-2 text-xl text-sky-100">Seja bem-vindo(a) de volta.</p>
                <p class="mt-4 max-w-xs text-sm leading-relaxed text-sky-200/80">
                    Entre para continuar acessando a plataforma de inteligência comercial da Autopel.
                </p>
            </div>
        </div>

        <div class="flex w-full flex-1 flex-col items-center justify-center bg-white px-6 py-10 md:py-12">
            <div class="w-full sm:max-w-md">
                <slot />
            </div>
        </div>
    </div>
</template>
