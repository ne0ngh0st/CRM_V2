<script setup>
/**
 * Faixa de atalho do Painel — a do Power BI (cyan) e a da Intranet (roxo pastel).
 *
 * Extraída em 2026-09-18, quando a intranet ganhou a SEGUNDA faixa: duas faixas iguais em
 * cores diferentes, cada uma com a própria cópia da marcação, é exatamente o caso da Regra
 * de ouro nº 8. A estrutura mora aqui; a cor é o `tom`.
 *
 * ⚠️ NÃO é um card sem corpo. A primeira versão azul do BI reusava a estrutura do
 * `DarkCard` (header de 3,5rem, título + subtítulo longo, botão contornado) e ficou pesada
 * justamente por isso: um header do tamanho dos outros, mas sem nada embaixo, lê como card
 * quebrado. Aqui a faixa é um objeto próprio — mais baixa que um header de card, a LINHA
 * INTEIRA é o link, e o texto de apoio é curto. Ressalva vai no `title`, não na manchete.
 *
 * ⚠️ TINT, e não cor CHAPADA — terceira e última correção de cor do BI (Tony, 09/09/2026:
 * "tá muito feio, acho que é sobre as cores"). O histórico das três:
 *   1. navy chapado  → escuro demais, virava mais uma faixa preta entre o PageHero e o
 *      header do card de baixo;
 *   2. cyan chapado  → o oposto: uma barra saturada de ponta a ponta gritava MAIS que o
 *      PageHero, invertendo a hierarquia da página (o atalho parecia o assunto principal);
 *   3. tint + um só elemento saturado ← esta. A faixa fica claramente colorida, mas a
 *      saturação mora só no botão e no filete. Fundo claro devolve ao texto o mesmo
 *      contraste que ele tem em qualquer card branco.
 *
 * ⚠️ A REGRA POR TRÁS: destaque é CONTRASTE COM A VIZINHANÇA, não quantidade de tinta. Numa
 * página de cards brancos com header preto, uma faixa CLARA colorida destoa tanto quanto
 * uma escura — e não compete com o título da página.
 *
 * ⚠️ "UM AZUL E UM ROXO POR PÁGINA." Cada cor de faixa só funciona por ser única: o azul é
 * a única coisa da Home que leva para FORA do CRM (o BI), o roxo é a única que leva à
 * intranet. Um terceiro atalho com cor própria faz os três pararem de destacar — se surgir,
 * a conversa é sobre hierarquia da página, não sobre qual cor usar.
 *
 * ⚠️ Texto ESCURO sempre. Branco sobre o cyan da marca dá 2,4:1 e some; o roxo pastel dá
 * ~2:1. O texto é o tom escuro de cada cor sobre tint claro.
 *
 * ⚠️ As classes de cada tom são STRINGS COMPLETAS no mapa abaixo, nunca montadas por
 * interpolação (`bg-${cor}/10`): o Tailwind só gera a classe que encontra escrita por
 * inteiro no código-fonte.
 */
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    tom: {
        type: String,
        required: true,
        validator: (v) => ['bi', 'intranet'].includes(v),
    },
    titulo: { type: String, required: true },
    href: { type: String, required: true },
    /** Externo abre em nova aba com `<a>`; interno navega pelo Inertia. */
    externo: { type: Boolean, default: false },
    /** Ressalva longa — vai para o tooltip nativo, não para a faixa. */
    dica: { type: String, default: null },
    rotuloBotao: { type: String, default: 'Abrir' },
});

const TONS = {
    bi: {
        faixa: 'border-cyan/40 bg-cyan/10 hover:border-cyan hover:bg-cyan/20',
        filete: 'bg-cyan',
        icone: 'text-cyan-dark',
        titulo: 'text-navy',
        apoio: 'text-navy/60',
        botao: 'bg-navy group-hover:bg-cyan-dark',
    },
    intranet: {
        faixa: 'border-intranet/60 bg-intranet/15 hover:border-intranet hover:bg-intranet/25',
        filete: 'bg-intranet',
        icone: 'text-intranet-dark',
        titulo: 'text-intranet-dark',
        apoio: 'text-intranet-dark/70',
        botao: 'bg-intranet-dark group-hover:bg-navy',
    },
};

const cor = TONS[props.tom];
</script>

<template>
    <component
        :is="externo ? 'a' : Link"
        :href="href"
        v-bind="externo ? { target: '_blank', rel: 'noopener noreferrer' } : {}"
        :title="dica"
        class="group flex items-center gap-3 overflow-hidden rounded border px-4 py-2.5 shadow-sm transition"
        :class="cor.faixa"
    >
        <!-- Filete cheio: é ele que marca a cor da faixa mesmo com o fundo claro. -->
        <span class="-my-2.5 -ml-4 mr-1 w-1.5 self-stretch" :class="cor.filete" />

        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-white shadow-sm" :class="cor.icone">
            <span class="h-4 w-4"><slot name="icon" /></span>
        </span>

        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold leading-tight" :class="cor.titulo">{{ titulo }}</span>
            <!--
                ⚠️ Corte com reticências só a partir de `sm`. Entre o ícone e o botão (os dois
                `shrink-0`) sobram ~160px a 320px, e nessa largura o `whitespace-nowrap`
                cortava a faixa do BI em "Faturamento no Power BI · abre…".
            -->
            <span
                v-if="$slots.default"
                class="block text-xs leading-snug sm:overflow-hidden sm:text-ellipsis sm:whitespace-nowrap"
                :class="cor.apoio"
            >
                <slot />
            </span>
        </span>

        <!-- Único elemento saturado da faixa: é ele que o olho procura. -->
        <span
            class="inline-flex shrink-0 items-center gap-1.5 rounded px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition"
            :class="cor.botao"
        >
            <slot name="selo" />
            {{ rotuloBotao }}
            <svg v-if="externo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3">
                <path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 01-1 1H6a1 1 0 01-1-1V7a1 1 0 011-1h4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3">
                <path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </component>
</template>
