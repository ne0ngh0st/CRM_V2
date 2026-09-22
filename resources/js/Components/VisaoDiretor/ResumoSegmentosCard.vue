<script setup>
/**
 * A aba DASHBOARD da planilha: contas, status e tamanho de mercado por segmento.
 *
 * ⚠️ É card, não tabela de dados: alinhamento esquerda/direita, filete entre linhas e
 * nenhuma divisória vertical — os tokens `.tbl*` dentro de card foram o erro do
 * `SegmentosInativosCard` (corrigido em 2026-09-10).
 *
 * Clicar numa linha abre a aba daquele segmento; clicar de novo volta ao Resumo.
 *
 * O especialista tem coluna própria, com foto — é a primeira coisa que o diretor procura
 * ao lado do nome do segmento ("quem cuida disto?"). Ele é marcado pela estrela no quadro
 * de Segmentos da Equipe; aqui só aparece.
 */
import DarkCard from '@/Components/DarkCard.vue';
import EspecialistaSelo from '@/Components/Segmentos/EspecialistaSelo.vue';
import { ROTULOS_STATUS_CONTA } from '@/constants/visaoDiretor';
import { formatInteiro } from '@/utils/formato';

defineProps({
    linhas: { type: Array, required: true },
    segmentoAtivo: { type: String, default: '' },
});

const emit = defineEmits(['filtrar']);
</script>

<template>
    <DarkCard title="Resumo por segmento" subtitle="Contas-alvo e tamanho de mercado. Clique para abrir a aba.">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M11 4.2a8 8 0 1 0 8.8 8.8H11Z" stroke-linejoin="round" />
                <path d="M14 3.2a7 7 0 0 1 6.8 6.8H14Z" stroke-linejoin="round" />
            </svg>
        </template>

        <p v-if="! linhas.length" class="py-6 text-center text-sm text-gray-400">Nenhuma conta cadastrada.</p>

        <ul v-else class="divide-y divide-gray-100">
            <li v-for="s in linhas" :key="s.codigo">
                <button
                    type="button"
                    class="grid w-full grid-cols-2 items-center gap-x-4 gap-y-1 px-2 py-2 text-left transition hover:bg-gray-50 sm:grid-cols-[minmax(10rem,1.3fr)_minmax(9rem,1.1fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.8fr)]"
                    :class="segmentoAtivo === s.codigo ? 'bg-gray-50 ring-1 ring-inset ring-teal/40' : ''"
                    :aria-pressed="segmentoAtivo === s.codigo"
                    @click="emit('filtrar', segmentoAtivo === s.codigo ? '' : s.codigo)"
                >
                    <span class="col-span-2 sm:col-span-1">
                        <span class="block text-sm font-semibold text-gray-800">{{ s.nome }}</span>
                        <span class="block text-[0.7rem] text-gray-400">{{ s.resumo.contas }} contas</span>
                    </span>

                    <span class="col-span-2 min-w-0 sm:col-span-1">
                        <EspecialistaSelo :especialista="s.especialista" />
                    </span>

                    <span class="text-xs text-gray-600">
                        <span class="font-semibold text-green-700">{{ s.resumo.ativo }}</span> {{ ROTULOS_STATUS_CONTA.ativo.toLowerCase() }}s
                        <span class="block text-[0.7rem] text-gray-400">
                            {{ s.resumo.inativando }} {{ ROTULOS_STATUS_CONTA.inativando.toLowerCase() }} ·
                            {{ s.resumo.inativo + s.resumo.lead }} a trabalhar/lead
                        </span>
                    </span>

                    <span class="text-xs text-gray-600">
                        <span class="block text-[0.65rem] uppercase tracking-wide text-gray-400">Filiais mercado</span>
                        <span class="tabular-nums">{{ formatInteiro(s.resumo.filiaisMercado) }}</span>
                    </span>

                    <span class="text-xs text-gray-600">
                        <span class="block text-[0.65rem] uppercase tracking-wide text-gray-400">Clientes</span>
                        <span class="tabular-nums">{{ formatInteiro(s.resumo.clientes) }}</span>
                    </span>
                </button>
            </li>
        </ul>
    </DarkCard>
</template>
