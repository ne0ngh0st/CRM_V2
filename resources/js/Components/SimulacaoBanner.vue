<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

// Vem de `HandleInertiaRequests` e é lido só da sessão — nenhuma query por request.
const simulacao = computed(() => page.props.simulacao ?? { ativa: false });
const alvo = computed(() => {
    const user = page.props.auth?.user;
    return user?.display_name || user?.name || 'usuário';
});

function encerrar() {
    router.post(route('simulacao.encerrar'));
}
</script>

<template>
    <!--
        Barra fixa no topo. Existe pra que o admin nunca esqueça que está na pele de
        outra pessoa — no legado não havia aviso nenhum, e o sintoma era achar que o
        sistema estava mostrando dados errados.
    -->
    <div
        v-if="simulacao.ativa"
        class="sticky top-0 z-50 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber px-4 py-2 text-center text-sm text-white shadow"
    >
        <span class="inline-flex items-center gap-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 shrink-0">
                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" stroke-linejoin="round" />
                <circle cx="12" cy="12" r="2.5" />
            </svg>
            Você está vendo o sistema como <strong>{{ alvo }}</strong>.
        </span>
        <span v-if="simulacao.adminNome" class="text-white/80">
            Sessão de {{ simulacao.adminNome }}.
        </span>
        <button
            type="button"
            class="rounded border border-white/60 px-2.5 py-0.5 text-xs font-semibold hover:bg-white/15"
            @click="encerrar"
        >
            Encerrar simulação
        </button>
    </div>
</template>
