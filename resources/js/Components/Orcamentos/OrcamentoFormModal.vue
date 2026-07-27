<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { OPCOES_FORMA_PAGAMENTO } from '@/constants/orcamentos.js';

const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
});

const emit = defineEmits(['close']);

function itemVazio() {
    return { tipo_item: '', cod_produto: '', descricao: '', quantidade: '1', valor_unitario: '', preco_tabela: '' };
}

const form = useForm({
    cliente_nome: '',
    cliente_cnpj: '',
    cliente_contato: '',
    forma_pagamento: '',
    data_validade: '',
    itens: [itemVazio()],
});

watch(
    () => props.show,
    (mostrando) => {
        if (!mostrando) return;

        if (props.orcamento) {
            form.cliente_nome = props.orcamento.clienteNome ?? '';
            form.cliente_cnpj = props.orcamento.clienteCnpj ?? '';
            form.cliente_contato = props.orcamento.clienteContato ?? '';
            form.forma_pagamento = props.orcamento.formaPagamento ?? '';
            form.data_validade = props.orcamento.dataValidade ?? '';
            form.itens = props.orcamento.itens.length
                ? props.orcamento.itens.map((i) => ({
                    tipo_item: i.tipoItem ?? '',
                    cod_produto: i.codProduto ?? '',
                    descricao: i.descricao,
                    quantidade: String(i.quantidade),
                    valor_unitario: String(i.valorUnitario),
                    preco_tabela: i.precoTabela !== null ? String(i.precoTabela) : '',
                }))
                : [itemVazio()];
        } else {
            form.reset();
            form.itens = [itemVazio()];
        }
    },
);

function adicionarItem() {
    form.itens.push(itemVazio());
}

function removerItem(idx) {
    if (form.itens.length > 1) form.itens.splice(idx, 1);
}

function descontoItem(item) {
    const precoTabela = parseFloat(item.preco_tabela);
    const valorUnitario = parseFloat(item.valor_unitario);
    if (!precoTabela || precoTabela <= 0 || isNaN(valorUnitario)) return null;

    return ((1 - valorUnitario / precoTabela) * 100).toFixed(1);
}

const valorTotalPreview = computed(() =>
    form.itens.reduce((soma, item) => {
        const qtd = parseFloat(item.quantidade) || 0;
        const vlr = parseFloat(item.valor_unitario) || 0;
        return soma + qtd * vlr;
    }, 0),
);

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function fechar() {
    form.clearErrors();
    emit('close');
}

function salvar() {
    const opcoes = {
        preserveScroll: true,
        onSuccess: () => fechar(),
    };

    if (props.orcamento) {
        form.patch(route('orcamentos.update', props.orcamento.id), opcoes);
    } else {
        form.post(route('orcamentos.store'), opcoes);
    }
}
</script>

<template>
    <Modal :show="show" max-width="4xl" @close="fechar">
        <form class="p-6" @submit.prevent="salvar">
            <h2 class="text-lg font-semibold text-gray-800">{{ orcamento ? 'Editar Orçamento' : 'Novo Orçamento' }}</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <InputLabel for="orc_cliente_nome" value="Cliente *" />
                    <TextInput id="orc_cliente_nome" v-model="form.cliente_nome" class="mt-1 block w-full" required />
                    <InputError :message="form.errors.cliente_nome" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="orc_cliente_cnpj" value="CNPJ" />
                    <TextInput id="orc_cliente_cnpj" v-model="form.cliente_cnpj" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cliente_cnpj" class="mt-1" />
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel for="orc_cliente_contato" value="Contato" />
                    <TextInput id="orc_cliente_contato" v-model="form.cliente_contato" class="mt-1 block w-full" />
                    <InputError :message="form.errors.cliente_contato" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="orc_forma_pagamento" value="Forma de Pagamento" />
                    <select id="orc_forma_pagamento" v-model="form.forma_pagamento" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan">
                        <option value="">Selecione</option>
                        <option v-for="opcao in OPCOES_FORMA_PAGAMENTO" :key="opcao" :value="opcao">{{ opcao }}</option>
                    </select>
                    <InputError :message="form.errors.forma_pagamento" class="mt-1" />
                </div>
                <div>
                    <InputLabel for="orc_data_validade" value="Data de Validade" />
                    <TextInput id="orc_data_validade" v-model="form.data_validade" type="date" class="mt-1 block w-full" />
                    <InputError :message="form.errors.data_validade" class="mt-1" />
                </div>
            </div>

            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Itens</h3>
                    <button type="button" class="rounded bg-teal px-3 py-1 text-xs font-medium text-white hover:bg-teal/90" @click="adicionarItem">
                        + Adicionar Item
                    </button>
                </div>
                <InputError :message="form.errors.itens" class="mt-1" />

                <div class="mt-3 flex flex-col gap-3">
                    <div v-for="(item, idx) in form.itens" :key="idx" class="rounded border border-gray-200 p-3">
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-2">
                                <InputLabel :for="`item_tipo_${idx}`" value="Tipo" />
                                <TextInput :id="`item_tipo_${idx}`" v-model="item.tipo_item" class="mt-1 block w-full" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel :for="`item_cod_${idx}`" value="Código" />
                                <TextInput :id="`item_cod_${idx}`" v-model="item.cod_produto" class="mt-1 block w-full" />
                            </div>
                            <div class="sm:col-span-4">
                                <InputLabel :for="`item_desc_${idx}`" value="Descrição *" />
                                <TextInput :id="`item_desc_${idx}`" v-model="item.descricao" class="mt-1 block w-full" required />
                            </div>
                            <div class="sm:col-span-1">
                                <InputLabel :for="`item_qtd_${idx}`" value="Qtd. *" />
                                <TextInput :id="`item_qtd_${idx}`" v-model="item.quantidade" type="number" min="0.01" step="0.01" class="mt-1 block w-full" required />
                            </div>
                            <div class="sm:col-span-1">
                                <InputLabel :for="`item_valor_${idx}`" value="Vlr. Unit. *" />
                                <TextInput :id="`item_valor_${idx}`" v-model="item.valor_unitario" type="number" min="0" step="0.01" class="mt-1 block w-full" required />
                            </div>
                            <div class="sm:col-span-1">
                                <InputLabel :for="`item_tabela_${idx}`" value="Pç. Tabela" />
                                <TextInput :id="`item_tabela_${idx}`" v-model="item.preco_tabela" type="number" min="0" step="0.01" class="mt-1 block w-full" />
                            </div>
                            <div class="flex items-end justify-between gap-2 sm:col-span-1">
                                <span
                                    v-if="descontoItem(item) !== null"
                                    class="rounded bg-gray-100 px-2 py-1.5 text-xs font-semibold text-gray-600"
                                    :title="'Preview — servidor recalcula o nível de aprovação'"
                                >
                                    {{ descontoItem(item) }}%
                                </span>
                                <button
                                    v-if="form.itens.length > 1"
                                    type="button"
                                    class="rounded border border-red-300 px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                    @click="removerItem(idx)"
                                >
                                    Remover
                                </button>
                            </div>
                        </div>
                        <InputError :message="form.errors[`itens.${idx}.descricao`]" class="mt-1" />
                        <InputError :message="form.errors[`itens.${idx}.quantidade`]" class="mt-1" />
                        <InputError :message="form.errors[`itens.${idx}.valor_unitario`]" class="mt-1" />
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between rounded border border-gray-200 bg-gray-50 px-3 py-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Valor total (preview)</span>
                <span class="text-sm font-bold text-navy">{{ formatBRL(valorTotalPreview) }}</span>
            </div>
            <p class="mt-1 text-xs text-gray-400">
                O nível de aprovação exigido (nenhum / supervisor / diretor) é sempre recalculado pelo servidor a partir do maior desconto entre os itens.
            </p>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="fechar">Cancelar</SecondaryButton>
                <PrimaryButton type="submit" :disabled="form.processing">{{ orcamento ? 'Salvar Alterações' : 'Criar Orçamento' }}</PrimaryButton>
            </div>
        </form>
    </Modal>
</template>
