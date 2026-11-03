<script setup>
/**
 * Cadastro de segmentos (admin + diretor). O código é a chave do TOTVS e só se escreve ao
 * criar; depois, muda-se nome e peso. Regras em `CadastroDeSegmentos`.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import DarkCard from '@/Components/DarkCard.vue';
import InputError from '@/Components/InputError.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    segmentos: { type: Array, required: true },
});

const busca = ref('');
const aberto = ref(false);
const editando = ref(null);
const excluindo = ref(null);

const form = useForm({ codigo: '', nome: '', peso: 0 });
const formExcluir = useForm({});

const lista = computed(() => {
    const termo = busca.value.trim().toLowerCase();

    return props.segmentos.filter(
        (s) => !termo || s.nome.toLowerCase().includes(termo) || s.codigo.includes(termo),
    );
});

function novo() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    aberto.value = true;
}

function editar(segmento) {
    editando.value = segmento;
    form.codigo = segmento.codigo;
    form.nome = segmento.nome;
    form.peso = segmento.peso;
    form.clearErrors();
    aberto.value = true;
}

function salvar() {
    const opcoes = {
        preserveScroll: true,
        onSuccess: () => {
            aberto.value = false;
        },
    };

    if (editando.value) {
        form.put(route('equipe.segmentos.update', editando.value.id), opcoes);
    } else {
        form.post(route('equipe.segmentos.store'), opcoes);
    }
}

function excluir() {
    formExcluir.delete(route('equipe.segmentos.destroy', excluindo.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            excluindo.value = null;
        },
    });
}

function fecharExclusao() {
    excluindo.value = null;
    formExcluir.clearErrors();
}

function motivoSemExcluir(segmento) {
    return segmento.podeExcluir ? 'Excluir' : 'Em uso: não pode ser excluído';
}

function numero(valor) {
    return Number(valor).toLocaleString('pt-BR');
}
</script>

<template>
    <DarkCard title="Cadastro de segmentos" subtitle="Nome e peso valem para a empresa inteira">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 6h16M4 12h16M4 18h10" stroke-linecap="round" />
            </svg>
        </template>
        <template #actions>
            <PrimaryButton type="button" @click="novo">Novo segmento</PrimaryButton>
        </template>

        <div class="flex flex-col gap-3">
            <div class="flex flex-col gap-1 sm:max-w-xs">
                <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                <input
                    v-model="busca"
                    type="text"
                    placeholder="Nome ou código..."
                    class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                />
            </div>

            <div class="tbl-wrap">
                <table class="tbl min-w-[720px]">
                    <thead>
                        <tr class="tbl-head-row">
                            <th class="tbl-th">Código</th>
                            <th class="tbl-th">Segmento</th>
                            <th class="tbl-th" title="Caixas que um cliente do segmento tende a comprar">Peso (cx/cliente)</th>
                            <th class="tbl-th">Clientes</th>
                            <th class="tbl-th">Leads</th>
                            <th class="tbl-th">Na equipe</th>
                            <th class="tbl-th">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="tbl-body">
                        <tr v-for="segmento in lista" :key="segmento.id" class="tbl-row">
                            <td class="tbl-td tabular-nums">{{ segmento.codigo }}</td>
                            <td class="tbl-td"><span class="tbl-main">{{ segmento.nome }}</span></td>
                            <td class="tbl-td tabular-nums">{{ numero(segmento.peso) }}</td>
                            <td class="tbl-td tabular-nums">{{ numero(segmento.clientes) }}</td>
                            <td class="tbl-td tabular-nums">{{ numero(segmento.leads) }}</td>
                            <td class="tbl-td tabular-nums">{{ numero(segmento.pessoas) }}</td>
                            <td class="tbl-td">
                                <div class="tbl-acoes">
                                    <button type="button" class="tbl-acao tbl-acao-teal" title="Editar" @click="editar(segmento)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 20h4L19 9l-4-4L4 16v4Z" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="tbl-acao tbl-acao-danger"
                                        :title="motivoSemExcluir(segmento)"
                                        :disabled="!segmento.podeExcluir"
                                        @click="excluindo = segmento"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-gray-500">
                O Painel reflete nome e peso em até 30 minutos. Só dá para excluir segmento sem cliente, lead nem pessoa.
            </p>
        </div>

        <ModalPadrao
            :show="aberto"
            :titulo="editando ? 'Editar segmento' : 'Novo segmento'"
            :subtitulo="editando ? `Código ${editando.codigo}` : 'Use o código do segmento no TOTVS'"
            @close="aberto = false"
        >
            <form id="form-segmento" class="flex flex-col gap-3" @submit.prevent="salvar">
                <div v-if="!editando">
                    <label for="segmento-codigo" class="text-xs font-medium text-gray-700">Código TOTVS</label>
                    <input id="segmento-codigo" v-model="form.codigo" type="text" inputmode="numeric" required class="mt-1 w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan" />
                    <InputError :message="form.errors.codigo" class="mt-1" />
                </div>
                <div>
                    <label for="segmento-nome" class="text-xs font-medium text-gray-700">Nome</label>
                    <input id="segmento-nome" v-model="form.nome" type="text" required maxlength="100" class="mt-1 w-full rounded border-gray-300 text-sm uppercase focus:border-cyan focus:ring-cyan" />
                    <InputError :message="form.errors.nome" class="mt-1" />
                </div>
                <div>
                    <label for="segmento-peso" class="text-xs font-medium text-gray-700">Peso (caixas por cliente, de 0 a 20)</label>
                    <input id="segmento-peso" v-model="form.peso" type="number" min="0" max="20" step="0.01" required class="mt-1 w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan" />
                    <InputError :message="form.errors.peso" class="mt-1" />
                </div>
            </form>

            <template #footer>
                <SecondaryButton type="button" @click="aberto = false">Cancelar</SecondaryButton>
                <PrimaryButton form="form-segmento" :disabled="form.processing">Salvar</PrimaryButton>
            </template>
        </ModalPadrao>

        <ConfirmacaoModal
            :show="Boolean(excluindo)"
            titulo="Excluir segmento"
            :subtitulo="excluindo?.nome ?? ''"
            mensagem="O segmento sai da lista do sistema."
            rotulo-confirmar="Excluir"
            tom="danger"
            :processando="formExcluir.processing"
            :erro="formExcluir.errors.segmento ?? ''"
            @confirmar="excluir"
            @close="fecharExclusao"
        />
    </DarkCard>
</template>
