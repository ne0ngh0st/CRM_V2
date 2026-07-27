<script setup>
defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (v) => ['ok', 'warn', 'danger', 'neutral'].includes(v),
    },
});

const toneClasses = {
    ok: 'bg-green-50 text-green-700 border-green-300',
    warn: 'bg-amber-50 text-amber-700 border-amber-300',
    danger: 'bg-red-50 text-red-700 border-red-300',
    neutral: 'bg-white/10 text-gray-200 border-white/20',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1 text-[0.68rem] font-bold uppercase tracking-wide"
        :class="toneClasses[tone]"
    >
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />
        <slot />
    </span>
</template>
