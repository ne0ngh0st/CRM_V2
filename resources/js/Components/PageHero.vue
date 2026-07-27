<script setup>
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="mb-4 overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 bg-corp-black px-4 py-3.5">
            <div class="min-w-0 flex-1">
                <h1 class="flex items-center gap-2 text-lg font-bold leading-tight text-gray-100">
                    <span v-if="$slots.icon" class="h-5 w-5 shrink-0 text-gray-400">
                        <slot name="icon" />
                    </span>
                    {{ title }}
                </h1>
                <p v-if="$slots.subtitle" class="mt-1 max-w-prose text-sm leading-snug text-gray-400">
                    <slot name="subtitle" />
                </p>
            </div>
            <div v-if="$slots.meta" class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                <slot name="meta" />
            </div>
        </div>
        <div v-if="$slots.filtros" class="flex flex-wrap items-end gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3">
            <slot name="filtros" />
        </div>
    </div>
</template>
