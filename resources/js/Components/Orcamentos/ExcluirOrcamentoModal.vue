<script setup>
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({});

function fechar() {
    form.clearErrors();
    emit('close');
}

function excluir() {
    form.delete(route('orcamentos.destroy', props.orcamento.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="sm" @close="fechar">
        <div v-if="orcamento" class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Excluir orçamento?</h2>
            <p class="mt-2 text-sm text-gray-600">
                Tem certeza que quer excluir o orçamento de <strong>{{ orcamento.clienteNome }}</strong> permanentemente? Essa ação não pode ser desfeita.
            </p>

            <InputError :message="form.errors.orcamento" class="mt-2" />

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <DangerButton type="button" :disabled="form.processing" @click="excluir">Excluir</DangerButton>
            </div>
        </div>
    </Modal>
</template>
