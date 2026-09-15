<script setup>
defineProps({
    label: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <!-- ⚠️ As larguras são `sm:`. Abaixo de 640px o campo mora empilhado (na faixa ou
         dentro do modal de filtros), e `max-w-[220px]` ali deixaria um select de 220px
         solto numa caixa de 280 — parece campo desalinhado, não campo compacto.
         O `w-full` do celular não é decoração: dentro do `flex-wrap` da faixa, sem largura
         declarada o campo assume a largura do conteúdo e dois selects dividem a linha em
         metades desiguais. -->
    <div class="flex w-full flex-col gap-1 sm:w-auto sm:min-w-[150px] sm:max-w-[220px] sm:flex-1">
        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">{{ label }}</label>
        <select
            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs uppercase text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <slot />
        </select>
    </div>
</template>
