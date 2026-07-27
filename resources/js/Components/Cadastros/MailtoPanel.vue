<script setup>
import { computed } from 'vue';

const props = defineProps({
    mailto: { type: Object, required: true },
});

const emit = defineEmits(['dismiss']);

const href = computed(() => {
    const params = new URLSearchParams();
    params.set('subject', props.mailto.subject || '');
    params.set('body', props.mailto.body || '');
    if (props.mailto.cc) params.set('cc', props.mailto.cc);
    return `mailto:${props.mailto.to}?${params.toString()}`;
});

async function copiar() {
    try {
        await navigator.clipboard.writeText(props.mailto.body || '');
    } catch {
        // ignore
    }
}
</script>

<template>
    <div class="rounded border border-amber-300 bg-amber-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-amber-900">E-mail pronto para envio</p>
                <p class="mt-0.5 text-xs text-amber-800">
                    Para: {{ mailto.to }}
                    <span v-if="mailto.cc"> · Cc: {{ mailto.cc }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a
                    :href="href"
                    class="rounded border border-amber-400 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100"
                >
                    Abrir no e-mail
                </a>
                <button
                    type="button"
                    class="rounded border border-amber-400 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100"
                    @click="copiar"
                >
                    Copiar texto
                </button>
                <button
                    type="button"
                    class="rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
                    @click="emit('dismiss')"
                >
                    Fechar
                </button>
            </div>
        </div>
        <textarea
            readonly
            class="mt-3 w-full rounded border-amber-200 bg-white text-xs text-gray-700"
            rows="6"
            :value="mailto.body"
        />
    </div>
</template>
