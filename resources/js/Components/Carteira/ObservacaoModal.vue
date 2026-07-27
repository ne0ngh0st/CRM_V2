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
const carregando = ref(false);
const historico = ref([]);
const salvoOk = ref(false);

async function carregarHistorico() {
    if (!props.cliente?.id) return;

    carregando.value = true;
    try {
        const { data } = await axios.get(route('observacoes.porCliente', props.cliente.id));
        historico.value = data;
    } catch {
        historico.value = [];
    } finally {
        carregando.value = false;
    }
}

watch(
    () => props.show,
    (mostrando) => {
        if (mostrando) {
            mensagem.value = '';
            erro.value = '';
            salvoOk.value = false;
            historico.value = [];
            carregarHistorico();
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
    salvoOk.value = false;
    try {
        const { data } = await axios.post(route('observacoes.store'), {
            cliente_id: props.cliente.id,
            cnpj: props.cliente.cnpj || undefined,
            mensagem: mensagem.value,
        });
        historico.value = [data, ...historico.value];
        mensagem.value = '';
        salvoOk.value = true;
    } catch (e) {
        const errors = e.response?.data?.errors;
        erro.value = errors
            ? Object.values(errors).flat().join(' ')
            : (e.response?.data?.message || 'Não foi possível salvar a observação.');
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="lg" @close="fechar">
        <div v-if="cliente" class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Observações</h2>
            <p class="mt-1 text-sm text-gray-500">{{ cliente.razaoSocial }} · {{ cliente.cnpj || 'CNPJ não cadastrado' }}</p>

            <div class="mt-4 max-h-56 space-y-2 overflow-y-auto">
                <p v-if="carregando" class="text-sm text-gray-400">Carregando histórico…</p>
                <p v-else-if="!historico.length" class="text-sm text-gray-400">Nenhuma observação ainda pra este cliente.</p>
                <div
                    v-for="obs in historico"
                    :key="obs.id"
                    class="rounded border border-gray-200 bg-gray-50 px-3 py-2"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-gray-600">{{ obs.autor }}</p>
                        <p class="text-[0.65rem] text-gray-400">{{ obs.criadoEm }}</p>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-gray-800">{{ obs.mensagem }}</p>
                </div>
            </div>

            <form class="mt-4 border-t border-gray-200 pt-4" @submit.prevent="salvar">
                <InputLabel for="observacao_mensagem" value="Nova observação *" />
                <textarea
                    id="observacao_mensagem"
                    v-model="mensagem"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                    required
                    autofocus
                />
                <InputError :message="erro" class="mt-1" />
                <p v-if="salvoOk" class="mt-1 text-xs font-medium text-emerald-600">Observação salva.</p>

                <div class="mt-4 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="fechar">Fechar</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="enviando">Salvar</PrimaryButton>
                </div>
            </form>
        </div>
    </Modal>
</template>
