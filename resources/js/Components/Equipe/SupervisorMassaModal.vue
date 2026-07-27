<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    usuarios: { type: Array, default: () => [] },
    opcoes: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({
    user_ids: [],
    novo_cod_super: '',
});

watch(
    () => props.usuarios,
    (usuarios) => {
        form.user_ids = usuarios.map((u) => u.id);
    },
);

function fechar() {
    form.reset();
    form.clearErrors();
    emit('close');
}

function salvar() {
    form.patch(route('equipe.supervisorMassa'), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="fechar">
        <form class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Reatribuir Supervisor em Massa</h2>
            <p class="mt-1 text-sm text-gray-500">{{ usuarios.length }} usuário{{ usuarios.length !== 1 ? 's' : '' }} selecionado{{ usuarios.length !== 1 ? 's' : '' }}</p>

            <ul class="mt-3 max-h-40 space-y-1 overflow-y-auto rounded border border-gray-100 bg-gray-50 p-2 text-sm text-gray-600">
                <li v-for="u in usuarios" :key="u.id">{{ u.nome }}</li>
            </ul>

            <div class="mt-4">
                <InputLabel for="massa_supervisor" value="Novo Supervisor *" />
                <select id="massa_supervisor" v-model="form.novo_cod_super" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan" required>
                    <option value="" disabled>Selecione</option>
                    <option value="__REMOVER__">Remover supervisor</option>
                    <option v-for="s in opcoes.supervisores" :key="s.codVendedor" :value="s.codVendedor">{{ s.nome }}</option>
                </select>
                <InputError :message="form.errors.novo_cod_super" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing || !usuarios.length">Reatribuir</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
