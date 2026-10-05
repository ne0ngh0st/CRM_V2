<script setup>
import { computed } from 'vue';
import { formatBRL, formatBRLCurto } from '@/utils/formato.js';

/*
 * Célula "Capital / Porte" da Carteira e dos Leads: capital social da EMPRESA na Receita
 * em cima, porte embaixo. Mesma gramática das outras células de duas linhas da tabela
 * (`.tbl-main` + `.tbl-sub`), sem cor nem pill: é dado de contexto, não alerta.
 *
 * O capital vai curto ("R$ 1,2 mi") e o valor exato no tooltip. O porte chega como
 * rótulo pronto do servidor (`PorteEmpresa::rotulo`) — o front não tem mapa.
 */
const props = defineProps({
    receita: { type: Object, default: null },
});

const capital = computed(() => props.receita?.capitalSocial ?? null);
const porte = computed(() => props.receita?.porte ?? null);

const titulo = computed(() => (capital.value === null
    ? 'Capital social não informado pela Receita'
    : `Capital social na Receita: ${formatBRL(capital.value)}`));
</script>

<template>
    <span v-if="capital === null && ! porte" class="text-gray-400" title="Ainda sem dado da Receita para este CNPJ">—</span>
    <template v-else>
        <!-- Capital zero (órgão público, associação, empresário individual) sai em cinza:
             numa carteira de governo seria um "R$ 0" em negrito por linha, e o destaque
             tem que ficar com quem declara capital. -->
        <span v-if="! capital" class="block whitespace-nowrap leading-4 text-gray-400" :title="titulo">{{ capital === null ? '—' : 'R$ 0' }}</span>
        <span v-else class="tbl-main whitespace-nowrap" :title="titulo">{{ formatBRLCurto(capital) }}</span>
        <span v-if="porte" class="tbl-sub whitespace-nowrap">{{ porte }}</span>
    </template>
</template>
