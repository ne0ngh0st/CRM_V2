<script setup>
import Modal from '@/Components/Modal.vue';

defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'escolher']);

const OPCOES = [
    { valor: 'bobina', label: 'Bobina', descricao: 'Bobina térmica, papel ou similar — incide IPI em modo Produto.' },
    { valor: 'etiqueta', label: 'Etiqueta', descricao: 'Etiqueta adesiva — nunca incide IPI, admin pode usar a calculadora de precificação.' },
    { valor: 'outro', label: 'Outro', descricao: 'Qualquer outro item do orçamento.' },
];

function escolher(valor) {
    emit('escolher', valor);
    emit('close');
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Que tipo de item?</h2>
            <div class="mt-4 flex flex-col gap-2">
                <button
                    v-for="opcao in OPCOES"
                    :key="opcao.valor"
                    type="button"
                    class="rounded border border-gray-200 p-3 text-left hover:border-cyan hover:bg-cyan/5"
                    @click="escolher(opcao.valor)"
                >
                    <span class="block text-sm font-semibold text-gray-800">{{ opcao.label }}</span>
                    <span class="block text-xs text-gray-500">{{ opcao.descricao }}</span>
                </button>
            </div>
        </div>
    </Modal>
</template>
