<script setup>
/**
 * Escolha visual dos segmentos de UMA pessoa. Cada tile é um interruptor —
 * selecionado = preenchido na cor do segmento. Sem checkbox, sem lista:
 * o gerente vê o mapa e clica.
 *
 * Representante não escolhe: a regra "só SUPERMERCADISTA" vale no servidor
 * (`SegmentoVendedorSync`). Aqui o tile único aparece travado para a tela
 * não fingir que a escolha existe.
 */
import { computed } from 'vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import { corDoSegmento } from '@/constants/segmentos.js';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    show: { type: Boolean, default: false },
    pessoa: { type: Object, default: null },
    segmentos: { type: Array, default: () => [] },
    codigoSupermercadista: { type: String, required: true },
    salvando: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'salvar']);

const ehRepresentante = computed(() => props.pessoa?.perfil === 'representante');

const selecionados = computed(() => new Set(props.pessoa?.segmentosIds ?? []));

function cor(segmento) {
    return corDoSegmento(segmento.codigo);
}

function ativo(segmento) {
    return selecionados.value.has(segmento.id);
}

function toggle(segmento) {
    if (!props.pessoa || ehRepresentante.value || props.salvando) {
        return;
    }

    const ids = ativo(segmento)
        ? props.pessoa.segmentosIds.filter((id) => id !== segmento.id)
        : [...props.pessoa.segmentosIds, segmento.id];

    emit('salvar', ids);
}
</script>

<template>
    <ModalPadrao
        :show="show"
        :titulo="pessoa ? pessoa.nome : 'Segmentos'"
        :subtitulo="pessoa ? `${ROTULOS_PERFIL[pessoa.perfil] || pessoa.perfil} · ${pessoa.codVendedor}` : ''"
        max-width="2xl"
        @close="emit('close')"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                <rect x="3.5" y="3.5" width="7" height="7" rx="1" />
                <rect x="13.5" y="3.5" width="7" height="7" rx="1" />
                <rect x="3.5" y="13.5" width="7" height="7" rx="1" />
                <rect x="13.5" y="13.5" width="7" height="7" rx="1" />
            </svg>
        </template>

        <p v-if="ehRepresentante" class="mb-3 rounded border border-teal/40 bg-teal/5 px-3 py-2 text-xs text-teal">
            Representante atende só SUPERMERCADISTA — sem exceção.
        </p>
        <p v-else class="mb-3 text-xs text-gray-500">
            Clique para ligar ou desligar. A mudança grava na hora.
        </p>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <button
                v-for="segmento in segmentos"
                :key="segmento.id"
                type="button"
                class="rounded border px-2.5 py-2 text-left transition"
                :class="ativo(segmento) ? 'shadow-sm' : 'opacity-70 hover:opacity-100'"
                :style="{
                    backgroundColor: ativo(segmento) ? cor(segmento).fundo : '#fff',
                    borderColor: ativo(segmento) ? cor(segmento).barra : '#d1d5db',
                    color: cor(segmento).texto,
                }"
                :disabled="ehRepresentante || salvando"
                :title="ativo(segmento) ? 'Clique para tirar deste segmento' : 'Clique para atribuir'"
                @click="toggle(segmento)"
            >
                <span
                    class="mb-1 block h-1.5 rounded-full"
                    :style="{ backgroundColor: cor(segmento).barra, width: ativo(segmento) ? '100%' : '30%' }"
                />
                <span class="block text-[0.7rem] font-semibold uppercase leading-4 tracking-wide">
                    {{ segmento.nome }}
                </span>
            </button>
        </div>
    </ModalPadrao>
</template>
