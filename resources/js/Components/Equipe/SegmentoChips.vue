<script setup>
defineProps({
    segmentos: { type: Array, default: () => [] },
});
</script>

<template>
    <div v-if="segmentos.length" class="flex flex-wrap gap-1">
        <span
            v-for="s in segmentos.slice(0, 2)"
            :key="s"
            class="inline-flex items-center rounded-full border border-gray-300 bg-gray-50 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-gray-600"
        >
            {{ s }}
        </span>
        <span
            v-if="segmentos.length > 2"
            class="inline-flex items-center rounded-full border border-gray-300 bg-gray-100 px-2 py-0.5 text-[0.65rem] font-medium text-gray-500"
            :title="segmentos.slice(2).join(', ')"
        >
            +{{ segmentos.length - 2 }}
        </span>
    </div>
    <span v-else class="text-xs text-gray-400">—</span>
</template>
