<script setup>
/**
 * O Resumo da Visão Diretor: por segmento, quantos clientes estão ativos, perdendo e a
 * trabalhar, e quantos leads (empresas onde nunca atuamos). Cada número é o tile da aba
 * daquele segmento — vêm da mesma tabela (`UniversoDoSegmento::resumo()`).
 *
 * ⚠️ É card, não tabela de dados: alinhamento esquerda/direita, filete entre linhas e
 * nenhuma divisória vertical — os tokens `.tbl*` dentro de card foram o erro do
 * `SegmentosInativosCard` (corrigido em 2026-09-10).
 *
 * Clicar numa linha abre a aba daquele segmento.
 */
import DarkCard from '@/Components/DarkCard.vue';
import EspecialistaSelo from '@/Components/Segmentos/EspecialistaSelo.vue';
import { ROTULOS_STATUS_CONTA } from '@/constants/visaoDiretor';
import { formatInteiro } from '@/utils/formato';

defineProps({
    linhas: { type: Array, required: true },
    subtitulo: { type: String, default: '' },
});

const emit = defineEmits(['abrir']);

const COLUNAS = [
    { chave: 'ativo', rotulo: 'Ativos', cor: 'text-green-700' },
    { chave: 'inativando', rotulo: ROTULOS_STATUS_CONTA.inativando, cor: 'text-amber-dark' },
    { chave: 'inativo', rotulo: ROTULOS_STATUS_CONTA.inativo, cor: 'text-red-700' },
    { chave: 'lead', rotulo: 'Leads', cor: 'text-gray-700' },
];
</script>

<template>
    <DarkCard title="Resumo por segmento" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M11 4.2a8 8 0 1 0 8.8 8.8H11Z" stroke-linejoin="round" />
                <path d="M14 3.2a7 7 0 0 1 6.8 6.8H14Z" stroke-linejoin="round" />
            </svg>
        </template>
        <template #actions>
            <slot name="actions" />
        </template>

        <!-- Cabeçalho das colunas de número (só onde há espaço para elas lado a lado). -->
        <div class="hidden grid-cols-[minmax(10rem,1.4fr)_minmax(9rem,1.2fr)_repeat(4,minmax(0,0.7fr))] gap-x-4 px-2 pb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400 sm:grid">
            <span>Segmento</span>
            <span>Especialista</span>
            <span v-for="c in COLUNAS" :key="c.chave" class="text-right">{{ c.rotulo }}</span>
        </div>

        <ul class="divide-y divide-gray-100">
            <li v-for="s in linhas" :key="s.codigo">
                <button
                    type="button"
                    class="grid w-full grid-cols-4 items-center gap-x-4 gap-y-1 px-2 py-2 text-left transition hover:bg-gray-50 sm:grid-cols-[minmax(10rem,1.4fr)_minmax(9rem,1.2fr)_repeat(4,minmax(0,0.7fr))]"
                    @click="emit('abrir', s.codigo)"
                >
                    <span class="col-span-4 sm:col-span-1">
                        <span class="block text-sm font-semibold text-gray-800">{{ s.nome }}</span>
                        <span class="block text-[0.7rem] text-gray-400">{{ formatInteiro(s.clientes) }} clientes · {{ formatInteiro(s.leads) }} leads</span>
                    </span>

                    <span class="col-span-4 min-w-0 sm:col-span-1">
                        <EspecialistaSelo :especialista="s.especialista" />
                    </span>

                    <span v-for="c in COLUNAS" :key="c.chave" class="text-right">
                        <span class="block text-[0.6rem] uppercase tracking-wide text-gray-400 sm:hidden">{{ c.rotulo }}</span>
                        <span class="text-sm font-semibold tabular-nums" :class="s[c.chave] ? c.cor : 'text-gray-300'">{{ formatInteiro(s[c.chave]) }}</span>
                    </span>
                </button>
            </li>
        </ul>
    </DarkCard>
</template>
