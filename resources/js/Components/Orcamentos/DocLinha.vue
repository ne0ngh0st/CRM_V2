<script setup>
// Linha rótulo/valor dentro de um DocPainel. Na tela o valor costuma ser um campo
// editável; no PDF é texto. Equivalente impresso: `.painel .rotulo` / `.painel .valor`.
defineProps({
    rotulo: { type: String, required: true },
});
</script>

<template>
    <div class="grid grid-cols-[38%_1fr] items-center gap-2 px-3 py-1.5">
        <span class="doc-rotulo">{{ rotulo }}</span>
        <div class="min-w-0">
            <slot />
        </div>
    </div>
</template>
