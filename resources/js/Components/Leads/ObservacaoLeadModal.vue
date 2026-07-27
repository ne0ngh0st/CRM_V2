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
    lead: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const mensagem = ref('');
const enviando = ref(false);
const erro = ref('');
const carregando = ref(false);
const historico = ref([]);
const salvoOk = ref(false);

async function carregarHistorico() {
    if (!props.lead?.id) return;
    carregando.value = true;
    try {
        const { data } = await axios.get(route('observacoes.porLead', props.lead.id));
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
            lead_id: props.lead.id,
            cnpj: props.lead.cnpj || undefined,
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
        <div v-if="lead" class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Observações</h2>
            <p class="mt-1 text-sm text-gray-500">{{ lead.razaoSocial || lead.nome }}</p>

            <div class="mt-4">
                <InputLabel for="mensagem_lead" value="Nova observação *" />
                <textarea
                    id="mensagem_lead"
                    v-model="mensagem"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                />
                <InputError :message="erro" class="mt-1" />
                <p v-if="salvoOk" class="mt-1 text-xs text-emerald-600">Observação salva.</p>
            </div>

            <div class="mt-4 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Fechar</SecondaryButton>
                <PrimaryButton type="button" :disabled="enviando || !mensagem.trim()" @click="salvar">
                    Salvar
                </PrimaryButton>
            </div>

            <div class="mt-6 border-t border-gray-200 pt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Histórico</p>
                <p v-if="carregando" class="mt-2 text-sm text-gray-400">Carregando…</p>
                <p v-else-if="!historico.length" class="mt-2 text-sm text-gray-400">Nenhuma observação ainda.</p>
                <ul v-else class="mt-2 max-h-60 space-y-2 overflow-y-auto">
                    <li
                        v-for="item in historico"
                        :key="item.id"
                        class="rounded border border-gray-200 px-3 py-2"
                    >
                        <div class="flex items-center justify-between gap-2 text-xs text-gray-400">
                            <span>{{ item.autor }}</span>
                            <span>{{ item.criadoEm }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-700">{{ item.mensagem }}</p>
                    </li>
                </ul>
            </div>
        </div>
    </Modal>
</template>
