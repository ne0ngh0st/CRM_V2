<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/*
 * Modal único de observações. Antes existiam dois (Carteira e Leads) com a mesma
 * função e layouts divergentes — um com o histórico em cima, outro embaixo.
 * Quem chama só informa de onde ler o histórico e o que mandar no POST.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    // Linha de contexto no header (razão social, nome do lead...).
    subtitulo: { type: String, default: '' },
    // GET que devolve o histórico. Null = abre sem histórico (ex.: form livre).
    historicoUrl: { type: String, default: null },
    // Campos extras do POST (cliente_id, lead_id, cnpj...).
    payload: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'salvo']);

const mensagem = ref('');
const enviando = ref(false);
const erro = ref('');
const carregando = ref(false);
const historico = ref([]);
const salvoOk = ref(false);
const campo = ref(null);

const podeSalvar = computed(() => mensagem.value.trim().length > 0 && !enviando.value);

async function carregarHistorico() {
    if (!props.historicoUrl) return;

    carregando.value = true;
    try {
        const { data } = await axios.get(props.historicoUrl);
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
        if (!mostrando) return;

        mensagem.value = '';
        erro.value = '';
        salvoOk.value = false;
        historico.value = [];
        carregarHistorico();
        nextTick(() => campo.value?.focus());
    },
);

function fechar() {
    emit('close');
}

async function salvar() {
    if (!podeSalvar.value) return;

    enviando.value = true;
    erro.value = '';
    salvoOk.value = false;
    try {
        const { data } = await axios.post(route('observacoes.store'), {
            ...props.payload,
            mensagem: mensagem.value,
        });
        historico.value = [data, ...historico.value];
        mensagem.value = '';
        salvoOk.value = true;
        emit('salvo', data);
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
    <ModalPadrao :show="show" titulo="Observações" :subtitulo="subtitulo" max-width="lg" @close="fechar">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                <line x1="8" y1="9" x2="16" y2="9" stroke-linecap="round" />
                <line x1="8" y1="12.5" x2="13" y2="12.5" stroke-linecap="round" />
            </svg>
        </template>

        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Histórico</p>

        <p v-if="carregando" class="mt-2 text-sm text-gray-400">Carregando histórico…</p>

        <div v-else-if="!historico.length" class="mt-2 rounded border border-dashed border-gray-300 px-3 py-5 text-center">
            <p class="text-sm text-gray-400">Nenhuma observação registrada ainda.</p>
        </div>

        <ul v-else class="mt-2 max-h-56 space-y-2 overflow-y-auto pr-1">
            <li
                v-for="obs in historico"
                :key="obs.id"
                class="rounded border border-gray-200 border-l-2 border-l-cyan bg-gray-50 px-3 py-2"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <p class="truncate text-xs font-semibold text-gray-700">{{ obs.autor }}</p>
                    <p class="shrink-0 text-[0.65rem] text-gray-400">{{ obs.criadoEm }}</p>
                </div>
                <p class="mt-1 whitespace-pre-wrap text-sm leading-snug text-gray-800">{{ obs.mensagem }}</p>
            </li>
        </ul>

        <form id="form-observacao" class="mt-4 border-t border-gray-200 pt-4" @submit.prevent="salvar">
            <label for="observacao_mensagem" class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                Nova observação
            </label>
            <textarea
                id="observacao_mensagem"
                ref="campo"
                v-model="mensagem"
                rows="3"
                placeholder="O que aconteceu no contato com este cliente?"
                class="mt-1 block w-full rounded border-gray-300 text-sm placeholder:text-gray-400 focus:border-cyan focus:ring-cyan"
                required
            />
            <InputError :message="erro" class="mt-1" />
            <p v-if="salvoOk" class="mt-1 text-xs font-medium text-emerald-600">Observação salva.</p>
        </form>

        <template #footer>
            <SecondaryButton type="button" @click="fechar">Fechar</SecondaryButton>
            <!-- `form=` liga o submit ao form acima, que ficou noutro slot. Mantém o
                 Enter no textarea funcionando e a validação nativa do required. -->
            <PrimaryButton type="submit" form="form-observacao" :disabled="!podeSalvar">
                {{ enviando ? 'Salvando…' : 'Salvar' }}
            </PrimaryButton>
        </template>
    </ModalPadrao>
</template>
