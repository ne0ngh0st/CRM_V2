<script setup>
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, default: 'Detalhes' },
    campos: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <div class="p-5">
            <h3 class="text-sm font-semibold text-gray-900">{{ titulo }}</h3>
            <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                <div v-for="campo in campos" :key="campo.label" class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                    <dt class="text-[0.65rem] font-semibold uppercase text-gray-400">{{ campo.label }}</dt>
                    <dd class="mt-0.5 break-words text-sm text-gray-800">{{ campo.value ?? '—' }}</dd>
                </div>
            </dl>
            <div class="mt-4 flex justify-end">
                <SecondaryButton @click="emit('close')">Fechar</SecondaryButton>
            </div>
        </div>
    </Modal>
</template>
