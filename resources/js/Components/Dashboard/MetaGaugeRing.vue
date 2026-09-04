<script setup>
/**
 * Anel de atingimento. O número é encurtado conforme cresce: até 99% com uma casa
 * decimal, de 100% em diante arredondado, e acima de 999% só "999+%".
 *
 * ⚠️ NÃO é firula. O anel tem 160px de viewBox e o texto é `text-xl`: com a aba Venda,
 * cujas metas podem estar zeradas ou cadastradas em ordem de grandeza errada, um
 * "37281.4%" transbordava o círculo e cobria a legenda. O valor exato continua legível
 * logo abaixo, no card que mostra realizado e meta lado a lado.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    label: { type: String, required: true },
    legenda: { type: String, required: true },
    dados: { type: Object, required: true },
    href: { type: String, default: null },
});

const raio = 62;
const circunferencia = 2 * Math.PI * raio;

const percentualVisual = computed(() => Math.min(props.dados.percentual, 100));
const dashOffset = computed(() => circunferencia - (percentualVisual.value / 100) * circunferencia);

/**
 * Ver o docblock do componente: percentual grande é encurtado para caber no anel.
 */
const textoPercentual = computed(() => {
    const pct = props.dados.percentual;

    if (pct >= 1000) return '999+%';
    if (pct >= 100) return `${Math.round(pct)}%`;

    return `${pct.toFixed(1).replace('.', ',')}%`;
});

const cor = computed(() => {
    if (props.dados.percentual >= 100) return '#22c55e';
    if (props.dados.percentual >= 80) return '#f59e0b';
    return '#ef4444';
});
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href ?? undefined" class="flex flex-col items-center" :class="href ? 'cursor-pointer transition hover:opacity-80' : ''">
        <svg viewBox="0 0 160 160" class="h-32 w-32">
            <circle cx="80" cy="80" :r="raio" fill="none" stroke="#e5e7eb" stroke-width="13" />
            <circle
                cx="80"
                cy="80"
                :r="raio"
                fill="none"
                :stroke="cor"
                stroke-width="13"
                stroke-linecap="round"
                :stroke-dasharray="circunferencia"
                :stroke-dashoffset="dashOffset"
                transform="rotate(-90 80 80)"
                class="transition-all duration-700 ease-out"
            />
            <text x="80" y="76" text-anchor="middle" class="fill-navy text-xl font-bold">
                {{ textoPercentual }}
            </text>
            <text x="80" y="94" text-anchor="middle" class="fill-gray-400 text-[10px] uppercase tracking-wide">
                {{ label }}
            </text>
        </svg>

        <p class="mt-1 text-xs font-medium text-gray-500">{{ legenda }}</p>
    </component>
</template>
