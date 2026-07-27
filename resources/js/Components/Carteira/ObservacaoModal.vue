<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    cliente: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const mensagem = ref('');
const enviando = ref(false);
const erro = ref('');

watch(
    () => props.show,
    (mostrando) => {
        if (mostrando) {
            mensagem.value = '';
            erro.value = '';
        }
    },
);

function fechar() {
    emit('close');
}

async function salvar() {
    if (!mensagem.value.trim()) return;

    enviando.value = true;
    erro.value = '';
    try {
        await axios.post(route('observacoes.store'), {
            cnpj: props.cliente.cnpj,
            mensagem: mensagem.value,
        });
        fechar();
    } catch (e) {
        erro.value = e.response?.data?.message || 'Não foi possível salvar a observação.';
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="md" @close="fechar">
        <form v-if="cliente" class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">Nova observação</h2>
            <p class="mt-1 text-sm text-gray-500">{{ cliente.razaoSocial }} · {{ cliente.cnpj }}</p>

            <div class="mt-4">
                <InputLabel for="observacao_mensagem" value="Mensagem *" />
                <textarea
                    id="observacao_mensagem"
                    v-model="mensagem"
                    rows="4"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                    required
                    autofocus
                />
                <InputError :message="erro" class="mt-1" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="enviando">Salvar</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
