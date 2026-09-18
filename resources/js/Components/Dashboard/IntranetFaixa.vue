<script setup>
/**
 * Atalho para a Intranet no Painel. Faixa roxa, primeiro bloco da página, para TODOS os
 * perfis (a do BI é só do gestor).
 *
 * O texto de apoio é o motivo de clicar: quantas publicações a pessoa ainda não abriu e,
 * separado, quantas regras esperam o "estou ciente" dela. Ler e dar ciência são coisas
 * diferentes — uma regra aberta e não confirmada continua pendente.
 */
import { computed } from 'vue';
import FaixaAtalho from '@/Components/FaixaAtalho.vue';
import IconeNav from '@/Components/Icones/IconeNav.vue';

const props = defineProps({
    naoLidas: { type: Number, default: 0 },
    cienciasPendentes: { type: Number, default: 0 },
});

const plural = (n, um, varios) => `${n > 9 ? '9+' : n} ${n === 1 ? um : varios}`;

const novidades = computed(() => (props.naoLidas > 0
    ? plural(props.naoLidas, 'novidade', 'novidades')
    : 'Avisos, regras, workflows e documentos'));

const ciencias = computed(() => (props.cienciasPendentes > 0
    ? plural(props.cienciasPendentes, 'regra aguardando sua ciência', 'regras aguardando sua ciência')
    : null));
</script>

<template>
    <FaixaAtalho tom="intranet" titulo="Intranet Autopel" :href="route('intranet.index')">
        <template #icon>
            <IconeNav nome="intranet" />
        </template>
        <span :class="naoLidas > 0 ? 'font-semibold' : ''">{{ novidades }}</span>
        <template v-if="ciencias">
            · <strong class="font-bold text-intranet-dark">{{ ciencias }}</strong>
        </template>
    </FaixaAtalho>
</template>
