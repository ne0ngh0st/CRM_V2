<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    cliente: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({
    motivo: '',
    observacao: '',
});

watch(
    () => props.show,
    (mostrando) => {
        if (mostrando) {
            form.reset();
            form.clearErrors();
        }
    },
);

function fechar() {
    form.clearErrors();
    emit('close');
}

function salvar() {
    form.post(route('carteira.motivoInatividade', props.cliente.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="fechar">
        <form v-if="cliente" class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Motivo de inatividade</h2>
            <p class="mt-1 text-sm text-gray-500">{{ cliente.razaoSocial }}</p>

            <div class="mt-4">
                <InputLabel for="motivo" value="Motivo *" />
                <TextInput id="motivo" v-model="form.motivo" class="mt-1 block w-full" required autofocus />
                <InputError :message="form.errors.motivo" class="mt-1" />
            </div>

            <div class="mt-4">
                <InputLabel for="observacao_motivo" value="Observação" />
                <textarea
                    id="observacao_motivo"
                    v-model="form.observacao"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                />
                <InputError :message="form.errors.observacao" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
