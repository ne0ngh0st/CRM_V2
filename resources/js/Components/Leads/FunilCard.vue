<script setup>
/**
 * Um lead no quadro do funil.
 *
 * ⚠️ O botão "→" é o caminho PRINCIPAL, não um atalho do arrastar. Arrastar não funciona
 * no celular e exige mira; o "→" responde exatamente à pergunta do vendedor — "já tratei
 * esse, joga pro próximo". O arrastar existe para quem está no desktop e prefere.
 *
 * O card mostra "parado há X dias" porque é isso que faz alguém agir: uma coluna cheia
 * não diz nada, mas "Negociação · parado há 12 dias" diz.
 */
import { ROTULOS_ETAPA_LEAD, rotuloParado, diasParado } from '@/constants/leads.js';
import { computed } from 'vue';

const props = defineProps({
    card: { type: Object, required: true },
    arrastando: { type: Boolean, default: false },
});

const emit = defineEmits(['avancar', 'mover', 'perder', 'ganhar', 'arrastar-inicio', 'arrastar-fim']);

/** Acima de 7 dias o card se destaca — é o gatilho de ação, não enfeite. */
const esquecido = computed(() => (diasParado(props.card.paradoDesde) ?? 0) >= 7);

const rotuloProxima = computed(() =>
    props.card.proximaEtapa ? `Avançar para ${ROTULOS_ETAPA_LEAD[props.card.proximaEtapa]}` : 'Última etapa do funil',
);
</script>

<template>
    <div
        class="group rounded border bg-white px-2.5 py-2 text-xs shadow-sm transition"
        :class="[
            arrastando ? 'opacity-40' : 'hover:border-cyan hover:shadow',
            esquecido ? 'border-l-2 border-l-amber border-gray-200' : 'border-gray-200',
        ]"
        draggable="true"
        @dragstart="emit('arrastar-inicio', card)"
        @dragend="emit('arrastar-fim')"
    >
        <p class="truncate font-medium leading-4 text-gray-700" :title="card.razaoSocial">
            {{ card.razaoSocial }}
        </p>
        <p v-if="card.local" class="truncate text-[0.65rem] leading-3 text-gray-400">{{ card.local }}</p>
        <p v-if="card.telefone" class="mt-0.5 text-[0.65rem] leading-3 text-gray-500">{{ card.telefone }}</p>

        <div class="mt-1.5 flex items-center justify-between gap-1">
            <span
                class="text-[0.65rem]"
                :class="esquecido ? 'font-semibold text-amber-dark' : 'text-gray-400'"
            >
                {{ rotuloParado(card.paradoDesde) }}
            </span>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="tbl-acao tbl-acao-verde"
                    title="Marcar como ganho"
                    @click="emit('ganhar', card)"
                >
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 10l4 4 8-8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="tbl-acao tbl-acao-danger"
                    title="Marcar como perdido"
                    @click="emit('perder', card)"
                >
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="tbl-acao tbl-acao-cyan"
                    :disabled="!card.proximaEtapa"
                    :title="rotuloProxima"
                    @click="emit('avancar', card)"
                >
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 10h11M11 6l4 4-4 4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
