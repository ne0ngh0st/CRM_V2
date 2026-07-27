<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({
    motivo_rejeicao: '',
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

function rejeitar() {
    form.patch(route('orcamentos.rejeitar', props.orcamento.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="fechar">
        <form v-if="orcamento" class="p-6" @submit.prevent="rejeitar">
            <h2 class="text-lg font-semibold text-gray-800">Rejeitar orçamento?</h2>
            <p class="mt-2 text-sm text-gray-600">
                Orçamento de <strong>{{ orcamento.clienteNome }}</strong> — {{ new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(orcamento.valorTotal) }}.
            </p>

            <div class="mt-4">
                <InputLabel for="motivo_rejeicao" value="Motivo da rejeição *" />
                <textarea
                    id="motivo_rejeicao"
                    v-model="form.motivo_rejeicao"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                    required
                />
                <InputError :message="form.errors.motivo_rejeicao" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <DangerButton type="submit" :disabled="form.processing">Rejeitar</DangerButton>
            </div>
        </form>
    </Modal>
</template>
