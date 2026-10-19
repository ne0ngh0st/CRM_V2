<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/*
 * Aceite do CLIENTE — o vendedor registra o que o cliente respondeu. Não é a aprovação
 * de margem (essa é do gestor). Recusa exige motivo; "Outros" exige texto.
 *
 * Componente próprio, e não `useConfirmacao`, porque o servidor pode recusar com erro de
 * validação e o erro tem que aparecer aqui dentro.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
    // Orcamento::MOTIVOS_RECUSA_CLIENTE, vindo pronto do servidor.
    motivos: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const form = useForm({
    resposta: '',
    motivo: '',
    motivo_texto: '',
});

// Abre com a resposta já registrada, para corrigir em vez de começar do zero.
watch(
    () => props.show,
    (mostrando) => {
        if (! mostrando || ! props.orcamento) return;
        form.clearErrors();
        form.resposta = props.orcamento.respostaCliente ?? '';
        form.motivo = props.orcamento.motivoRecusaCliente ?? '';
        form.motivo_texto = props.orcamento.motivoRecusaClienteTexto ?? '';
    },
);

const subtitulo = computed(() => (props.orcamento
    ? `#${props.orcamento.id} · ${props.orcamento.clienteNome}`
    : ''));

function fechar() {
    form.clearErrors();
    emit('close');
}

function salvar() {
    form
        .transform((dados) => ({
            resposta: dados.resposta,
            motivo: dados.resposta === 'recusado' ? dados.motivo || null : null,
            motivo_texto: dados.resposta === 'recusado' && dados.motivo === 'outros' ? dados.motivo_texto : null,
        }))
        .patch(route('orcamentos.respostaCliente', props.orcamento.id), {
            preserveScroll: true,
            onSuccess: () => fechar(),
        });
}

const opcoes = [
    { valor: 'aprovado', rotulo: 'Cliente aceitou', classe: 'border-green-500 bg-green-50 text-green-800' },
    { valor: 'recusado', rotulo: 'Cliente não aceitou', classe: 'border-red-500 bg-red-50 text-red-800' },
];
</script>

<template>
    <ModalPadrao :show="show" titulo="Resposta do cliente" :subtitulo="subtitulo" max-width="md" @close="fechar">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                <path d="M8 12.5 11 15.5 16.5 9" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="12" cy="12" r="9" />
            </svg>
        </template>

        <form v-if="orcamento" id="form-resposta-cliente" class="space-y-4" @submit.prevent="salvar">
            <p class="text-xs text-gray-500">
                É o aceite do cliente, não a aprovação de margem. Sem o aceite o orçamento não vira pedido.
            </p>

            <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Resposta do cliente">
                <button
                    v-for="op in opcoes"
                    :key="op.valor"
                    type="button"
                    role="radio"
                    :aria-checked="form.resposta === op.valor"
                    class="min-h-11 rounded border px-3 py-2 text-sm font-medium transition"
                    :class="form.resposta === op.valor ? op.classe : 'border-gray-300 text-gray-600 hover:bg-gray-50'"
                    @click="form.resposta = op.valor"
                >
                    {{ op.rotulo }}
                </button>
            </div>
            <InputError :message="form.errors.resposta" />

            <template v-if="form.resposta === 'recusado'">
                <div>
                    <label for="motivo-recusa" class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Motivo *</label>
                    <select
                        id="motivo-recusa"
                        v-model="form.motivo"
                        class="mt-1 block min-h-11 w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan sm:min-h-0"
                    >
                        <option value="" disabled>Escolha o motivo…</option>
                        <option v-for="(rotulo, chave) in motivos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                    <InputError :message="form.errors.motivo" class="mt-1" />
                </div>

                <div v-if="form.motivo === 'outros'">
                    <label for="motivo-texto" class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Qual motivo? *</label>
                    <textarea
                        id="motivo-texto"
                        v-model="form.motivo_texto"
                        rows="3"
                        maxlength="500"
                        class="mt-1 block w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                    />
                    <InputError :message="form.errors.motivo_texto" class="mt-1" />
                </div>
            </template>

            <p v-if="orcamento.respostaClientePorNome" class="text-[0.7rem] text-gray-400">
                Última resposta registrada por {{ orcamento.respostaClientePorNome }} em {{ orcamento.respostaClienteEm }}.
            </p>
        </form>

        <template #footer>
            <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
            <PrimaryButton type="submit" form="form-resposta-cliente" :disabled="form.processing || ! form.resposta">Salvar</PrimaryButton>
        </template>
    </ModalPadrao>
</template>
