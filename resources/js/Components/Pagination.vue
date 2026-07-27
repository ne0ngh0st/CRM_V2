<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    meta: { type: Object, required: true },
    only: { type: Array, default: () => [] },
});
</script>

<template>
    <div v-if="meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-3">
        <p class="text-xs text-gray-500">
            Mostrando <strong class="font-semibold text-gray-700">{{ meta.from }}</strong>–<strong class="font-semibold text-gray-700">{{ meta.to }}</strong>
            de <strong class="font-semibold text-gray-700">{{ meta.total }}</strong>
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, idx) in meta.links" :key="idx">
                <span
                    v-if="!link.url"
                    class="rounded px-2.5 py-1 text-xs font-medium text-gray-300"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-state
                    preserve-scroll
                    :only="only"
                    class="rounded px-2.5 py-1 text-xs font-medium"
                    :class="link.active ? 'bg-teal text-white' : 'text-gray-600 hover:bg-gray-100'"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
