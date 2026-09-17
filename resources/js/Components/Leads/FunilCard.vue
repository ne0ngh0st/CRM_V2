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
import {
    ETAPA_OUTROS,
    ROTULOS_ETAPA_LEAD,
    ROTULOS_ORIGEM_LEAD,
    rotuloParado,
    diasParado,
} from '@/constants/leads.js';
import { computed } from 'vue';

const props = defineProps({
    card: { type: Object, required: true },
    arrastando: { type: Boolean, default: false },
});

const emit = defineEmits([
    'avancar',
    'mover',
    'perder',
    'ganhar',
    'tirar-do-funil',
    'devolver-ao-funil',
    'arrastar-inicio',
    'arrastar-fim',
]);

/** Fora do funil comercial: SAC, licitação, currículo, fornecedor. Ver `Lead::ETAPA_OUTROS`. */
const foraDoFunil = computed(() => props.card.etapa === ETAPA_OUTROS);

/**
 * Acima de 7 dias o card se destaca — é o gatilho de ação, não enfeite.
 *
 * ⚠️ Não vale para quem está fora do funil: "parado há 40 dias" ali leria como negócio
 * esquecido, quando o certo é o oposto — aquilo já foi triado e não pede ação nenhuma.
 * Um punhado de cartões âmbar permanentes é o que faz o sinal deixar de ser lido.
 */
const esquecido = computed(() => !foraDoFunil.value && (diasParado(props.card.paradoDesde) ?? 0) >= 7);

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
            <!--
                Fora do funil não conta dias parado (ver `esquecido`): no lugar vai a
                origem, que é o que explica por que aquele contato chegou até aqui.
            -->
            <span
                v-if="foraDoFunil"
                class="truncate text-[0.65rem] text-gray-400"
            >
                {{ ROTULOS_ORIGEM_LEAD[card.origem] || card.origem }}
            </span>
            <span
                v-else
                class="text-[0.65rem]"
                :class="esquecido ? 'font-semibold text-amber-dark' : 'text-gray-400'"
            >
                {{ rotuloParado(card.paradoDesde) }}
            </span>

            <!--
                ⚠️ Fora do funil, só o caminho de volta. Ganho e Perdido ali seriam
                mentira — não houve negócio para ganhar nem para perder —, e é essa
                distinção que mantém a taxa de conversão do funil honesta.
            -->
            <div v-if="foraDoFunil" class="flex items-center gap-1">
                <button
                    type="button"
                    class="tbl-acao tbl-acao-cyan"
                    title="Devolver ao funil (volta para Novo)"
                    @click="emit('devolver-ao-funil', card)"
                >
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 10H5M9 6l-4 4 4 4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>

            <div v-else class="flex items-center gap-1">
                <button
                    type="button"
                    class="tbl-acao tbl-acao-neutro"
                    title="Não é venda — tirar do funil (SAC, licitação, currículo)"
                    @click="emit('tirar-do-funil', card)"
                >
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 4h14v4H3zM4.5 8v7a1 1 0 001 1h9a1 1 0 001-1V8M8 11h4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
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
