<script setup>
// Painel emoldurado com faixa de cabeçalho, usado nos dois blocos do topo do
// orçamento (cliente e proposta).
// Equivalente impresso: `.painel` em resources/views/orcamentos/pdf.blade.php.
defineProps({
    titulo: { type: String, required: true },
});
</script>

<template>
    <div class="border border-gray-200">
        <p class="border-b border-gray-200 bg-slate-100 px-3 py-1.5 text-[0.7rem] font-bold uppercase tracking-[0.1em] text-navy">
            {{ titulo }}
        </p>
        <div class="divide-y divide-gray-100">
            <slot />
        </div>
    </div>
</template>
