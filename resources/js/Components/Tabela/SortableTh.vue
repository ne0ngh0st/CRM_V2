<script setup>
import { computed } from 'vue';

/*
 * Header de coluna clicável. Emite o valor pronto do parâmetro `ordenar`
 * ("<campo>_<asc|desc>"), no mesmo formato que o backend faz o parse — a lista de
 * campos aceitos é whitelist no controller, então campo inventado aqui não vira SQL.
 *
 * Só marcar como ordenável a coluna que o backend sabe ordenar; caso contrário o
 * clique não faz nada visível e parece bug.
 */
const props = defineProps({
    campo: { type: String, required: true },
    ordenar: { type: String, default: '' },
});

const emit = defineEmits(['ordenar']);

const atual = computed(() => {
    const partes = /^(.*)_(asc|desc)$/.exec(props.ordenar || '');

    return partes ? { campo: partes[1], direcao: partes[2] } : { campo: null, direcao: null };
});

const ativo = computed(() => atual.value.campo === props.campo);
const direcao = computed(() => (ativo.value ? atual.value.direcao : null));

const ariaSort = computed(() => {
    if (!ativo.value) return 'none';

    return direcao.value === 'asc' ? 'ascending' : 'descending';
});

function alternar() {
    emit('ordenar', `${props.campo}_${ativo.value && direcao.value === 'asc' ? 'desc' : 'asc'}`);
}
</script>

<template>
    <th class="tbl-th" :aria-sort="ariaSort">
        <button
            type="button"
            class="group mx-auto inline-flex items-center gap-1 uppercase tracking-wide transition hover:text-gray-800"
            :class="ativo ? 'text-gray-800' : 'text-gray-500'"
            :title="ativo && direcao === 'asc' ? 'Ordenado crescente — clique pra inverter' : 'Clique pra ordenar'"
            @click="alternar"
        >
            <slot />
            <!-- Seta cheia quando a coluna está ordenando; fantasma no hover quando não. -->
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                class="h-3 w-3 shrink-0 transition"
                :class="[
                    ativo ? 'text-cyan-dark' : 'text-gray-300 opacity-0 group-hover:opacity-100',
                    ativo && direcao === 'desc' ? 'rotate-180' : '',
                ]"
            >
                <path d="m6 14 6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </th>
</template>
