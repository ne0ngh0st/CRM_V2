<script setup>
/**
 * "Nossas lojas ÷ filiais da rede no mercado", como barra + número.
 *
 * Componente porque aparece em dois lugares que precisam concordar — a tabela de contas e
 * o resumo por segmento. A barra satura em 100%: acima disso (pontos de entrega contados
 * como loja, rede que declarou menos filiais do que tem) o NÚMERO continua dizendo a
 * verdade, e a barra só não estoura a célula.
 */
import { computed } from 'vue';
import { formatPercentual } from '@/utils/formato';

const props = defineProps({
    valor: { type: Number, default: null },
});

const largura = computed(() => `${Math.min(100, Math.max(0, (props.valor ?? 0) * 100))}%`);
</script>

<template>
    <div class="flex min-w-[5.5rem] items-center gap-2" :title="valor === null ? 'Filiais de mercado não informadas' : ''">
        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200">
            <div v-if="valor !== null" class="h-full rounded-full bg-teal" :style="{ width: largura }" />
        </div>
        <span class="w-11 shrink-0 text-right text-xs tabular-nums text-gray-600">{{ formatPercentual(valor) }}</span>
    </div>
</template>
