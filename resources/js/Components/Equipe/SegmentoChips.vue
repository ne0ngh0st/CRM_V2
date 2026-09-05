<script setup>
/**
 * Chips com os segmentos de um vendedor. Mostra 2 e resume o resto em "+N", com os nomes
 * escondidos no `title` — a lista completa estouraria a largura da célula na Equipe.
 *
 * ⚠️ `surface` espelha a prop de mesmo nome do StatusPill: `light` (padrão) para fundo
 * branco, `dark` para o header preto do DarkCard. É vocabulário que o design system já
 * tinha; inventar outro nome aqui é o começo de duas convenções para a mesma coisa.
 */
defineProps({
    segmentos: { type: Array, default: () => [] },
    surface: {
        type: String,
        default: 'light',
        validator: (v) => ['light', 'dark'].includes(v),
    },
});

const ESTILOS = {
    light: {
        chip: 'border-gray-300 bg-gray-50 text-gray-600',
        resto: 'border-gray-300 bg-gray-100 text-gray-500',
        vazio: 'text-gray-400',
    },
    dark: {
        chip: 'border-white/25 bg-white/10 text-gray-100',
        resto: 'border-white/20 bg-white/5 text-gray-300',
        vazio: 'text-gray-400',
    },
};
</script>

<template>
    <div v-if="segmentos.length" class="flex flex-wrap gap-1">
        <span
            v-for="s in segmentos.slice(0, 2)"
            :key="s"
            class="inline-flex items-center rounded-full border px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide"
            :class="ESTILOS[surface].chip"
        >
            {{ s }}
        </span>
        <span
            v-if="segmentos.length > 2"
            class="inline-flex items-center rounded-full border px-2 py-0.5 text-[0.65rem] font-medium"
            :class="ESTILOS[surface].resto"
            :title="segmentos.slice(2).join(', ')"
        >
            +{{ segmentos.length - 2 }}
        </span>
    </div>
    <span v-else class="text-xs" :class="ESTILOS[surface].vazio">—</span>
</template>
