<script setup>
/**
 * Atalho para o Power BI. Faixa fina, na primeira dobra da Home do gestor.
 *
 * ⚠️ SEM IFRAME desde 2026-09-06, a pedido do diretor: "retira esse painel de BI e troca
 * por um botão que leva para o navegador ver o BI". O embed ocupava 560px e, para quem não
 * estava autenticado na conta Microsoft, mostrava só a tela de login do Power BI — que foi
 * exatamente a captura que ele mandou.
 *
 * A estrutura e todo o histórico de cor da faixa (navy → cyan chapado → tint) moram em
 * `FaixaAtalho.vue` desde 2026-09-18, quando a intranet ganhou uma faixa irmã em roxo.
 */
import FaixaAtalho from '@/Components/FaixaAtalho.vue';

defineProps({
    url: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <FaixaAtalho
        tom="bi"
        titulo="Dashboard BI"
        :href="url"
        externo
        dica="Abre o Power BI em nova aba. Exige conta Microsoft Pro, e o seletor de visão desta página não filtra lá."
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <rect x="7" y="11" width="3" height="5" rx="0.5" />
                <rect x="12" y="8" width="3" height="8" rx="0.5" />
                <rect x="17" y="5" width="3" height="11" rx="0.5" />
            </svg>
        </template>
        Faturamento no Power BI · abre em nova aba
    </FaixaAtalho>
</template>
