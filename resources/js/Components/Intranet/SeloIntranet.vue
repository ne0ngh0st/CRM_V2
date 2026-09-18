<script setup>
/**
 * Selo das publicações da intranet: categoria, "Novo", "Importante", "Ciência pendente",
 * "Ciente", "Fixada". Usado na lista e na leitura — por isso é componente (Regra de ouro
 * nº 8): a mesma publicação não pode ter selo de um jeito na lista e de outro ao abrir.
 *
 * ⚠️ A categoria usa o tint roxo da seção e NÃO muda de cor por categoria. Quatro cores
 * para quatro categorias competiriam com os selos que carregam ação (importante,
 * pendente) — que são os que precisam chamar o olho.
 */
defineProps({
    tom: {
        type: String,
        default: 'categoria',
        validator: (v) => ['categoria', 'novo', 'importante', 'pendente', 'ciente', 'fixada'].includes(v),
    },
});

const TONS = {
    categoria: 'border-intranet/60 bg-intranet/15 text-intranet-dark',
    novo: 'border-intranet-dark bg-intranet-dark text-white',
    importante: 'border-red-300 bg-red-50 text-red-700',
    pendente: 'border-amber-300 bg-amber-50 text-amber-700',
    ciente: 'border-green-300 bg-green-50 text-green-700',
    fixada: 'border-gray-300 bg-gray-100 text-gray-600',
};
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap rounded-full border px-2 py-0.5 text-[0.62rem] font-bold uppercase tracking-wide"
        :class="TONS[tom]"
    >
        <slot />
    </span>
</template>
