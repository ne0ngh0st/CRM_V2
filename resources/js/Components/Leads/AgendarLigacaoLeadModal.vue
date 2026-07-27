<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    lead: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({
    data_agendamento: '',
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
    form.post(route('leads.agendamento', props.lead.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="fechar">
        <form v-if="lead" class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Agendar ligação</h2>
            <p class="mt-1 text-sm text-gray-500">{{ lead.razaoSocial || lead.nome }}</p>

            <div class="mt-4">
                <InputLabel for="data_agendamento_lead" value="Data e hora *" />
                <TextInput
                    id="data_agendamento_lead"
                    v-model="form.data_agendamento"
                    type="datetime-local"
                    class="mt-1 block w-full"
                    required
                    autofocus
                />
                <InputError :message="form.errors.data_agendamento" class="mt-1" />
            </div>

            <div class="mt-4">
                <InputLabel for="obs_agendamento_lead" value="Observação" />
                <textarea
                    id="obs_agendamento_lead"
                    v-model="form.observacao"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                />
                <InputError :message="form.errors.observacao" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">Agendar</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
