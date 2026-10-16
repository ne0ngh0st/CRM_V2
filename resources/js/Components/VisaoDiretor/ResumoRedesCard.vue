<script setup>
/**
 * Resumo da página Maiores redes: por categoria da planilha, quantas redes são Ativas
 * (pelo menos um cliente nosso ativo) e quantas estão A trabalhar. Os números são os
 * tiles de cada aba (mesma tabela, `MaioresRedesResolver::resumo()`).
 *
 * ⚠️ Card, não tabela de dados: sem `.tbl*` (lição do `SegmentosInativosCard`).
 */
import DarkCard from '@/Components/DarkCard.vue';
import { formatInteiro } from '@/utils/formato';

defineProps({
    linhas: { type: Array, required: true },
    subtitulo: { type: String, default: '' },
});

const emit = defineEmits(['abrir']);

const COLUNAS = [
    { chave: 'total', rotulo: 'Redes', cor: 'text-gray-700' },
    { chave: 'ativo', rotulo: 'Ativas', cor: 'text-green-700' },
    { chave: 'a_trabalhar', rotulo: 'A trabalhar', cor: 'text-amber-dark' },
];
</script>

<template>
    <DarkCard title="Resumo por categoria" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M11 4.2a8 8 0 1 0 8.8 8.8H11Z" stroke-linejoin="round" />
                <path d="M14 3.2a7 7 0 0 1 6.8 6.8H14Z" stroke-linejoin="round" />
            </svg>
        </template>
        <template #actions>
            <slot name="actions" />
        </template>

        <div class="hidden grid-cols-[minmax(12rem,2fr)_repeat(3,minmax(0,0.7fr))] gap-x-4 px-2 pb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400 sm:grid">
            <span>Categoria</span>
            <span v-for="c in COLUNAS" :key="c.chave" class="text-right">{{ c.rotulo }}</span>
        </div>

        <ul class="divide-y divide-gray-100">
            <li v-for="s in linhas" :key="s.categoria">
                <button
                    type="button"
                    class="grid w-full grid-cols-3 items-center gap-x-4 gap-y-1 px-2 py-2 text-left transition hover:bg-gray-50 sm:grid-cols-[minmax(12rem,2fr)_repeat(3,minmax(0,0.7fr))]"
                    @click="emit('abrir', s.categoria)"
                >
                    <span class="col-span-3 text-sm font-semibold text-gray-800 sm:col-span-1">{{ s.nome }}</span>
                    <span v-for="c in COLUNAS" :key="c.chave" class="text-right">
                        <span class="block text-[0.6rem] uppercase tracking-wide text-gray-400 sm:hidden">{{ c.rotulo }}</span>
                        <span class="text-sm font-semibold tabular-nums" :class="s[c.chave] ? c.cor : 'text-gray-300'">{{ formatInteiro(s[c.chave]) }}</span>
                    </span>
                </button>
            </li>
        </ul>
    </DarkCard>
</template>
