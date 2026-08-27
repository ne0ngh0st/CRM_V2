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
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-start text-base font-medium text-indigo-700 bg-indigo-50 focus:outline-none focus:text-indigo-800 focus:bg-indigo-100 focus:border-indigo-700 transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes" :prefetch="prefetch" :cache-for="cacheFor">
        <slot />
    </Link>
</template>
