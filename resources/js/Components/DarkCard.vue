<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="flex h-full flex-col overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <div class="flex min-h-[3.5rem] flex-wrap items-center justify-between gap-3 border-b border-black/40 bg-corp-black px-4 py-2.5">
            <div class="min-w-0 flex-1">
                <h3 class="flex items-center gap-2 text-sm font-semibold leading-tight text-gray-100">
                    <span v-if="$slots.icon" class="h-4 w-4 shrink-0 text-gray-400">
                        <slot name="icon" />
                    </span>
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="mt-0.5 overflow-hidden text-ellipsis whitespace-nowrap text-xs leading-snug text-gray-400">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <div class="flex-1 p-4">
            <slot />
        </div>
    </div>
</template>
