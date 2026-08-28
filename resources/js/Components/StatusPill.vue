<script setup>
defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (v) => ['ok', 'warn', 'danger', 'neutral'].includes(v),
    },
    // 'light' = badge sobre fundo branco/claro (linha de tabela, corpo de card).
    // 'dark' = badge sobre o header preto do DarkCard/PageHero (slot #meta/#actions).
    surface: {
        type: String,
        default: 'light',
        validator: (v) => ['light', 'dark'].includes(v),
    },
    // 'sm' = dentro de linha de tabela, onde a badge é o que define a altura da linha.
    // 'md' = pills do PageHero/DarkCard, onde há espaço de sobra.
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md'].includes(v),
    },
});

const sizeClasses = {
    sm: 'gap-1 px-2 py-0.5 text-[0.62rem]',
    md: 'gap-1.5 px-2.5 py-1 text-[0.68rem]',
};

const dotClasses = {
    sm: 'h-1 w-1',
    md: 'h-1.5 w-1.5',
};

// Slot #icon opcional: substitui a bolinha por um SVG, para as raras pills em que o
// símbolo carrega significado próprio (ex.: o fogo do cache aquecido no Painel).
// Sem o slot, a bolinha continua sendo o padrão — nenhuma pill existente muda.
const iconClasses = {
    sm: 'h-2.5 w-2.5',
    md: 'h-3 w-3',
};

const toneClasses = {
    light: {
        ok: 'bg-green-50 text-green-700 border-green-300',
        warn: 'bg-amber-50 text-amber-700 border-amber-300',
        danger: 'bg-red-50 text-red-700 border-red-300',
        neutral: 'bg-gray-100 text-gray-600 border-gray-300',
    },
    dark: {
        ok: 'bg-green-500/10 text-green-400 border-green-500/30',
        warn: 'bg-amber-500/10 text-amber-400 border-amber-400/40',
        danger: 'bg-red-500/10 text-red-400 border-red-500/30',
        neutral: 'bg-white/10 text-gray-200 border-white/20',
    },
};
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap rounded-full border font-bold uppercase tracking-wide"
        :class="[toneClasses[surface][tone], sizeClasses[size]]"
    >
        <span v-if="$slots.icon" class="shrink-0" :class="iconClasses[size]">
            <slot name="icon" />
        </span>
        <span v-else class="shrink-0 rounded-full bg-current" :class="dotClasses[size]" />
        <slot />
    </span>
</template>
