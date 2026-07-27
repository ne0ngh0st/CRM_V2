<script setup>
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    usuario: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({ senha: '' });

function fechar() {
    form.reset();
    form.clearErrors();
    emit('close');
}

function salvar() {
    form.patch(route('equipe.senha', props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <Modal :show="show" max-width="sm" @close="fechar">
        <form v-if="usuario" class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Trocar Senha</h2>
            <p class="mt-1 text-sm text-gray-500">{{ usuario.nome }}</p>

            <div class="mt-4">
                <InputLabel for="nova_senha" value="Nova Senha *" />
                <TextInput id="nova_senha" v-model="form.senha" type="password" class="mt-1 block w-full" required minlength="6" autofocus />
                <InputError :message="form.errors.senha" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">Trocar Senha</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
