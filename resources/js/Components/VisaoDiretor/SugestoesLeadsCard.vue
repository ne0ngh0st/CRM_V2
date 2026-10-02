<script setup>
/**
 * Leads da prospecção que o import achou parecidos com uma rede da lista, mas que vieram
 * sem a coluna `rede` preenchida (`ContaDoLead`). Sugestão não é verdade: só aparece na
 * coluna Atendimento depois de alguém confirmar aqui.
 *
 * ⚠️ Recusar NÃO apaga a sugestão: o lead guarda a conta como recusada, e é isso que
 * impede o próximo import de sugerir a mesma de novo.
 *
 * Dentro de card, sem `.tbl*` (lição do SegmentosInativosCard, 10/09): esquerda/direita
 * e filete entre as linhas.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';

defineProps({
    sugestoes: { type: Array, required: true },
});

const enviando = ref(null);

function decidir(sugestao, acao) {
    enviando.value = sugestao.leadId;

    router.post(route(`visao-diretor.maiores.sugestao.${acao}`, sugestao.leadId), {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['dados', 'sugestoesLeads'],
        onFinish: () => { enviando.value = null; },
    });
}
</script>

<template>
    <DarkCard
        title="Leads da prospecção para confirmar"
        :subtitle="`${sugestoes.length} lead${sugestoes.length === 1 ? '' : 's'} com nome parecido com uma rede da lista`"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1" stroke-linecap="round" />
                <path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" stroke-linecap="round" />
            </svg>
        </template>

        <ul class="max-h-80 divide-y divide-gray-100 overflow-y-auto">
            <li
                v-for="s in sugestoes"
                :key="s.leadId"
                class="flex flex-wrap items-center justify-between gap-2 py-2"
            >
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-800" :title="s.razaoSocial">{{ s.razaoSocial }}</p>
                    <p class="text-[0.7rem] text-gray-500">
                        {{ s.cnpj || 'Sem CNPJ' }}<span v-if="s.local"> · {{ s.local }}</span>
                    </p>
                </div>

                <p class="text-xs text-gray-500">
                    é da rede
                    <strong class="font-semibold text-gray-800">{{ s.contaNome }}</strong>
                    <span v-if="s.segmento" class="text-gray-400"> · {{ s.segmento }}</span>?
                </p>

                <div class="flex shrink-0 gap-1">
                    <button
                        type="button"
                        class="rounded border border-green-700 px-2 py-1 text-xs font-medium text-green-700 transition hover:bg-green-50 disabled:opacity-50"
                        :disabled="enviando === s.leadId"
                        @click="decidir(s, 'confirmar')"
                    >Confirmar</button>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100 disabled:opacity-50"
                        :disabled="enviando === s.leadId"
                        @click="decidir(s, 'recusar')"
                    >Não é</button>
                </div>
            </li>
        </ul>
    </DarkCard>
</template>
