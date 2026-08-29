<script setup>
// Bloco de seção do documento de orçamento: legenda + corpo emoldurado.
// Equivalente impresso: `.legenda` / `.caixa` em resources/views/orcamentos/pdf.blade.php.
defineProps({
    titulo: { type: String, required: true },
    // A tabela de itens traz a própria moldura, então dispensa a caixa externa
    // (senão ficam duas bordas coladas).
    comCaixa: { type: Boolean, default: true },
});
</script>

<template>
    <section class="mt-4">
        <p class="doc-legenda">{{ titulo }}</p>
        <div v-if="comCaixa" class="doc-caixa">
            <slot />
        </div>
        <slot v-else />
    </section>
</template>
