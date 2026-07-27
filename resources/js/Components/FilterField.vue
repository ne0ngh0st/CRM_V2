<script setup>
defineProps({
    label: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex min-w-[150px] max-w-[220px] flex-1 flex-col gap-1">
        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">{{ label }}</label>
        <select
            class="w-full rounded border-gray-300 py-1.5 text-xs uppercase text-gray-700 focus:border-cyan focus:ring-cyan"
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <slot />
        </select>
    </div>
</template>
