<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    titulo: { type: String, required: true },
    dados: { type: Object, required: true },
    termo: { type: String, required: true },
    href: { type: String, default: null },
});

const diferenca = computed(() => Math.abs(props.dados.faturamento - props.dados.meta));

const tier = computed(() => {
    if (props.dados.meta <= 0) return 'sem';
    if (props.dados.percentual >= 100) return 'atingida';
    if (props.dados.percentual >= 80) return 'proxima';
    return 'baixa';
});

const statusTxt = computed(() => {
    if (tier.value === 'sem') return `${props.termo} não cadastrad${props.termo === 'Objetivo' ? 'o' : 'a'}`;
    if (tier.value === 'atingida') return `${props.termo} Atingid${props.termo === 'Objetivo' ? 'o' : 'a'}!`;
    if (tier.value === 'proxima') return `Próximo d${props.termo === 'Objetivo' ? 'o' : 'a'} ${props.termo}`;
    return `Abaixo d${props.termo === 'Objetivo' ? 'o' : 'a'} ${props.termo}`;
});

const detalhe = computed(() => {
    if (tier.value === 'sem') return '';
    if (tier.value === 'atingida') {
        return `${props.termo} superad${props.termo === 'Objetivo' ? 'o' : 'a'} em ${formatBRL(diferenca.value)}`;
    }
    return `Faltam ${formatBRL(diferenca.value)}`;
});

const tierBorder = {
    baixa: 'border-l-[#ffb3ba]',
    proxima: 'border-l-[#ffd3b6]',
    atingida: 'border-l-[#a8e6cf]',
    sem: 'border-l-gray-300',
};

const tierStatus = {
    baixa: 'border-[#ffb3ba] bg-[#fff0f1] text-red-700',
    proxima: 'border-[#ffd3b6] bg-[#fff8f0] text-amber-700',
    atingida: 'border-[#a8e6cf] bg-green-50 text-green-700',
    sem: 'border-gray-200 bg-gray-50 text-gray-500',
};

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="block border border-gray-300 border-l-[3px] bg-gray-50 p-3"
        :class="[tierBorder[tier], href ? 'cursor-pointer transition hover:bg-gray-100/80' : '']"
    >
        <div class="mb-2 flex items-center justify-between gap-2 border-b border-gray-200 pb-2">
            <span class="text-[0.7rem] font-bold uppercase tracking-wide text-gray-700">{{ titulo }}</span>
            <span
                v-if="tier !== 'sem'"
                class="shrink-0 text-sm font-extrabold tabular-nums"
                :class="{
                    'text-red-700': tier === 'baixa',
                    'text-amber-700': tier === 'proxima',
                    'text-green-700': tier === 'atingida',
                }"
            >
                {{ dados.percentual.toFixed(1).replace('.', ',') }}%
            </span>
        </div>

        <div class="grid grid-cols-2 gap-x-3 gap-y-1">
            <div class="min-w-0">
                <span class="block text-[0.65rem] font-bold uppercase tracking-wide text-gray-500">Realizado</span>
                <span class="mt-0.5 block text-sm font-extrabold tabular-nums leading-tight text-gray-900">
                    {{ formatBRL(dados.faturamento) }}
                </span>
            </div>
            <div class="min-w-0">
                <span class="block text-[0.65rem] font-bold uppercase tracking-wide text-gray-500">{{ termo }}</span>
                <span class="mt-0.5 block text-sm font-extrabold tabular-nums leading-tight text-gray-900">
                    {{ formatBRL(dados.meta) }}
                </span>
            </div>
        </div>

        <div
            v-if="detalhe || tier === 'sem'"
            class="mt-2 flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 rounded border px-2.5 py-1.5 text-[0.7rem] leading-snug"
            :class="tierStatus[tier]"
        >
            <span class="shrink-0 font-bold">{{ statusTxt }}</span>
            <span v-if="detalhe" class="min-w-0 font-semibold">{{ detalhe }}</span>
        </div>
    </component>
</template>
