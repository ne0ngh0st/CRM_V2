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
    /**
     * O tile representa o recorte que está aplicado na tela.
     *
     * ⚠️ Só faz sentido quando o tile é um FILTRO da própria tela (os três status da
     * Carteira), e aí `href` deve ser o link que REMOVE o filtro — chip selecionado se
     * desmarca no clique. Num tile que só navega para outro lugar, marcar não significa
     * nada.
     */
    ativo: { type: Boolean, default: false },
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

/*
 * O selecionado troca o cinza do tile por branco e ganha a borda na própria cor do tom —
 * a mesma que o número já usa, então nada de cor nova entra na tela. É a linguagem de
 * chip marcado, e é o que permite dizer "você está vendo este" sem escrever uma frase.
 *
 * ⚠️ `border` sozinho, sem `ring`: com os dois o tile engorda visualmente e a fileira
 * inteira parece desalinhada (o não-selecionado fica 2px mais estreito de aparência).
 */
const toneBorda = {
    default: 'border-navy',
    ok: 'border-emerald-500',
    warn: 'border-amber',
    danger: 'border-red-500',
    info: 'border-cyan',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="flex-1 basis-auto rounded border px-2.5 py-2 text-center"
        :class="[
            ativo ? [toneBorda[tone], 'bg-white shadow-sm'] : 'border-gray-200 bg-gray-50',
            href ? 'cursor-pointer transition hover:border-navy hover:bg-white hover:shadow-sm' : '',
        ]"
        :title="ativo ? 'Filtro aplicado — clique para remover' : undefined"
        :aria-current="ativo ? 'true' : undefined"
    >
        <p
            class="truncate font-bold leading-tight"
            :class="[toneText[tone], compact ? 'text-sm' : 'text-lg']"
            :title="String(value)"
        >
            {{ value }}
        </p>
        <p
            class="mt-1 flex items-center justify-center gap-1 truncate text-[0.65rem] font-medium uppercase leading-tight tracking-normal"
            :class="ativo ? toneText[tone] : 'text-gray-500'"
            :title="label"
        >
            {{ label }}
            <!--
                O "×" diz as duas coisas de uma vez — que este é o recorte aplicado e que
                dá para sair dele — sem gastar uma linha de texto na altura do tile.
            -->
            <span v-if="ativo" aria-hidden="true" class="font-normal opacity-70">✕</span>
        </p>
    </component>
</template>
