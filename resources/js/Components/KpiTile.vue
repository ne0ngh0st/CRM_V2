<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    value: { type: [String, Number], required: true },
    label: { type: String, required: true },
    tone: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'ok', 'warn', 'danger', 'info'].includes(v),
    },
    compact: { type: Boolean, default: false },
    href: { type: String, default: null },
});

const toneText = {
    default: 'text-navy',
    ok: 'text-emerald-600',
    warn: 'text-amber',
    danger: 'text-red-500',
    info: 'text-cyan',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="min-w-[84px] flex-1 basis-0 rounded border border-gray-200 bg-gray-50 px-2 py-2 text-center"
        :class="href ? 'cursor-pointer transition hover:border-navy hover:bg-white hover:shadow-sm' : ''"
    >
        <p
            class="truncate font-bold leading-tight"
            :class="[toneText[tone], compact ? 'text-sm' : 'text-lg']"
            :title="String(value)"
        >
            {{ value }}
        </p>
        <p class="mt-1 truncate text-[0.65rem] font-medium uppercase leading-tight tracking-normal text-gray-500" :title="label">
            {{ label }}
        </p>
    </component>
</template>
