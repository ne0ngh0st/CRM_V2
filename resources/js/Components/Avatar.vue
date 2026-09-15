<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, default: null },
    nome: { type: String, default: '' },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md'].includes(v),
    },
});

const quebrou = ref(false);

watch(() => props.src, () => {
    quebrou.value = false;
});

const mostrarFoto = computed(() => Boolean(props.src) && !quebrou.value);

const iniciais = computed(() => {
    const particulas = new Set(['de', 'da', 'do', 'das', 'dos', 'e']);
    const partes = props.nome
        .trim()
        .split(/\s+/)
        .filter((p) => p && !particulas.has(p.toLowerCase()));

    if (partes.length === 0) {
        const cru = props.nome.trim();
        return cru ? cru.slice(0, 2).toUpperCase() : '?';
    }

    if (partes.length === 1) {
        return partes[0].slice(0, 2).toUpperCase();
    }

    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
});

const sizeClasses = {
    sm: 'h-7 w-7 text-[0.6rem]',
    md: 'h-8 w-8 text-xs',
};
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-navy font-semibold uppercase leading-none text-white"
        :class="sizeClasses[size]"
        :title="nome"
        aria-hidden="true"
    >
        <img
            v-if="mostrarFoto"
            :src="src"
            alt=""
            class="h-full w-full object-cover"
            @error="quebrou = true"
        />
        <span v-else>{{ iniciais }}</span>
    </span>
</template>
