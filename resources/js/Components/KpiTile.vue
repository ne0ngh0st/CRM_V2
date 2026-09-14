<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    value: { type: [String, Number], required: true },
    label: { type: String, required: true },
    tone: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'ok', 'warn', 'danger', 'info'].includes(v),
    },
    compact: { type: Boolean, default: false },
    href: { type: String, default: null },
});

/*
 * ⚠️ `basis-auto`, NUNCA `basis-0` (e sem `min-w-[…]`): é o que impede o número de
 * sair truncado.
 *
 * Com `flex-1 basis-0` todo tile da fileira recebia a MESMA largura, calculada a
 * partir do zero — então o tile de dinheiro, que é o mais largo, era espremido ao
 * tamanho do tile de contagem e o `truncate` comia o valor ("R$ 24.575…", "VALOR EM
 * RIS…"), justamente no número que a pessoa abriu a tela para ler. O `min-w-[84px]`
 * piorava: `min-width` explícito substitui o `auto` do flex e AUTORIZA o item a
 * encolher abaixo do próprio conteúdo.
 *
 * Com `basis-auto` e o `min-width: auto` de volta, o tile nunca fica menor que o
 * texto: quando a fileira não cabe, ele quebra linha (o container é `flex-wrap`),
 * que é o comportamento previsto no Design System. O `truncate` continua aqui só
 * como rede para o caso extremo de um tile sozinho mais largo que o card.
 */
const toneText = {
    default: 'text-navy',
    ok: 'text-emerald-600',
    warn: 'text-amber',
    danger: 'text-red-500',
    info: 'text-cyan',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="flex-1 basis-auto rounded border border-gray-200 bg-gray-50 px-2.5 py-2 text-center"
        :class="href ? 'cursor-pointer transition hover:border-navy hover:bg-white hover:shadow-sm' : ''"
    >
        <p
            class="truncate font-bold leading-tight"
            :class="[toneText[tone], compact ? 'text-sm' : 'text-lg']"
            :title="String(value)"
        >
            {{ value }}
        </p>
        <p class="mt-1 truncate text-[0.65rem] font-medium uppercase leading-tight tracking-normal text-gray-500" :title="label">
            {{ label }}
        </p>
    </component>
</template>
