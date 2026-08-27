<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
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
</script>

<template>
    <Link
        :href="href"
        :prefetch="prefetch"
        :cache-for="cacheFor"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none"
    >
        <slot />
    </Link>
</template>
