<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    /*
     * Prefetch do Inertia v2, repassado explicitamente em vez de contar com fallthrough
     * de atributo: declarado como prop, fica visível na assinatura do componente e não
     * depende de o `<Link>` continuar sendo o nó raiz do template.
     *
     * 'hover'  → busca ao passar o mouse (o Inertia já espera ~75ms, o que filtra o
     *            mouse passando de raspão a caminho de outro item).
     * 'click'  → busca no mousedown; compra ~100-200ms sem multiplicar carga.
     * false    → não prefetcha (o padrão, e obrigatório em ações como logout).
     */
    prefetch: {
        type: [Boolean, String],
        default: false,
    },
    /** Quanto tempo a resposta prefetchada é reaproveitada antes de buscar de novo. */
    cacheFor: {
        type: String,
        default: '30s',
    },
});

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 text-sm font-medium leading-5 text-gray-900 focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes" :prefetch="prefetch" :cache-for="cacheFor">
        <slot />
    </Link>
</template>
