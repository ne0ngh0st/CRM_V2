<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatusPill from '@/Components/StatusPill.vue';
import OrcamentoSheet from '@/Components/Orcamentos/OrcamentoSheet.vue';
import ClienteBuscaBar from '@/Components/Orcamentos/ClienteBuscaBar.vue';
import ItensTabela from '@/Components/Orcamentos/ItensTabela.vue';
import {
    OPCOES_FORMA_PAGAMENTO,
    ROTULOS_STATUS_ORCAMENTO,
    TONS_STATUS_ORCAMENTO,
    ROTULOS_TIPO_PRODUTO_SERVICO,
    ROTULOS_TIPO_FRETE,
} from '@/constants/orcamentos.js';

const props = defineProps({
    role: String,
    isAdmin: Boolean,
    orcamento: { type: Object, default: null },
    prefillCliente: { type: Object, default: null },
    copiaDe: { type: Object, default: null },
    materiasPrimas: { type: Array, default: () => [] },
    outrasInformacoesPadrao: Object,
});

// Editar usa props.orcamento (PATCH); copiar usa props.copiaDe (POST, documento novo).
// Ambos têm a mesma estrutura de campos, só o modo de submissão muda.
const fonte = props.orcamento ?? props.copiaDe;

function itemVazio() {
    return {
        tipo_item: 'outro',
        cod_produto: '',
        descricao: '',
        quantidade: '1',
        valor_unitario: '',
        preco_tabela: '',
        calcula_ipi: true,
        etiqueta_calc: null,
        materia_prima_id: null,
    };
}

const form = useForm({
    cliente_nome: fonte?.clienteNome ?? props.prefillCliente?.nome ?? '',
    cliente_cnpj: fonte?.clienteCnpj ?? props.prefillCliente?.cnpj ?? '',
    cliente_contato: fonte?.clienteContato ?? props.prefillCliente?.contato ?? '',
    forma_pagamento: fonte?.formaPagamento ?? '',
    tipo_frete: fonte?.tipoFrete ?? 'CIF',
    tipo_produto_servico: fonte?.tipoProdutoServico ?? 'produto',
    data_validade: fonte?.dataValidade ?? new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10),
    observacoes: fonte?.observacoes ?? '',
    variacao_producao_personalizado: fonte?.variacaoProducaoPersonalizado ?? props.outrasInformacoesPadrao.variacao_producao_personalizado,
    prazo_producao: fonte?.prazoProducao ?? props.outrasInformacoesPadrao.prazo_producao,
    garantia_imagem: fonte?.garantiaImagem ?? props.outrasInformacoesPadrao.garantia_imagem,
    texto_importante: fonte?.textoImportante ?? props.outrasInformacoesPadrao.texto_importante,
    itens: fonte?.itens?.length
        ? fonte.itens.map((i) => ({
            tipo_item: i.tipoItem,
            cod_produto: i.codProduto ?? '',
            descricao: i.descricao,
            quantidade: String(i.quantidade),
            valor_unitario: String(i.valorUnitario),
            preco_tabela: i.precoTabela !== null ? String(i.precoTabela) : '',
            calcula_ipi: i.calculaIpi,
            etiqueta_calc: i.etiquetaCalc ?? null,
            materia_prima_id: i.materiaPrimaId ?? null,
        }))
        : [itemVazio()],
});

// "Outros" no select revela um campo de texto livre — como o forma_pagamento
// já é string livre no banco, o valor digitado vai direto pra form.forma_pagamento.
const OPCOES_FIXAS = OPCOES_FORMA_PAGAMENTO.slice(0, -1);
const modoFormaPagamentoLivre = ref(form.forma_pagamento !== '' && !OPCOES_FIXAS.includes(form.forma_pagamento));

function selecionarFormaPagamento(valor) {
    if (valor === '__livre__') {
        modoFormaPagamentoLivre.value = true;
        form.forma_pagamento = '';

        return;
    }

    form.forma_pagamento = valor;
}

function selecionarCliente(cliente) {
    form.cliente_nome = cliente.nome ?? '';
    form.cliente_cnpj = cliente.cnpj ?? '';
    form.cliente_contato = cliente.telefone ?? '';
}

const IPI_ALIQUOTA = 0.0325;
function baseSemIpi(valor) {
    return valor / (1 + IPI_ALIQUOTA);
}
function participaIpi(item) {
    return form.tipo_produto_servico === 'produto' && item.tipo_item !== 'etiqueta' && !!item.calcula_ipi;
}

const resumo = computed(() => {
    let subtotalProdutosSemIpi = 0;
    let subtotalProdutosComIpi = 0;
    let subtotalEtiquetas = 0;

    for (const item of form.itens) {
        const qtd = parseFloat(item.quantidade) || 0;
        const valorUnit = parseFloat(item.valor_unitario) || 0;
        const totalComIpi = qtd * valorUnit;
        const totalSemIpi = qtd * (participaIpi(item) ? baseSemIpi(valorUnit) : valorUnit);

        if (item.tipo_item === 'etiqueta') {
            subtotalEtiquetas += totalComIpi;
        } else {
            subtotalProdutosComIpi += totalComIpi;
            subtotalProdutosSemIpi += totalSemIpi;
        }
    }

    return {
        subtotalProdutosSemIpi,
        subtotalProdutosComIpi,
        subtotalEtiquetas,
        totalGeral: subtotalProdutosComIpi + subtotalEtiquetas,
    };
});

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor || 0);
}

function salvar() {
    if (props.orcamento) {
        form.patch(route('orcamentos.update', props.orcamento.id));
    } else {
        form.post(route('orcamentos.store'));
    }
}
</script>

<template>
    <Head :title="orcamento ? 'Editar Orçamento' : 'Novo Orçamento'" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link :href="route('orcamentos.index')" class="inline-flex w-fit items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                            <polyline points="15,6 9,12 15,18" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Voltar aos orçamentos
                    </Link>

                    <div class="flex items-center gap-2">
                        <StatusPill v-if="orcamento" :tone="TONS_STATUS_ORCAMENTO[orcamento.statusGestor] ?? 'neutral'">
                            {{ ROTULOS_STATUS_ORCAMENTO[orcamento.statusGestor] ?? orcamento.statusGestor }}
                        </StatusPill>
                        <SecondaryButton
                            v-if="orcamento"
                            type="button"
                            title="Cria um orçamento novo com os mesmos dados, pronto pra editar"
                            @click="router.visit(route('orcamentos.novo', { copiar_de: orcamento.id }))"
                        >
                            Copiar
                        </SecondaryButton>
                        <SecondaryButton type="button" @click="router.visit(route('orcamentos.index'))">Cancelar</SecondaryButton>
                        <PrimaryButton type="submit" form="form-orcamento" :disabled="form.processing" class="!bg-teal hover:!bg-teal/90">
                            {{ orcamento ? 'Salvar Alterações' : 'Criar Orçamento' }}
                        </PrimaryButton>
                    </div>
                </div>

                <form id="form-orcamento" @submit.prevent="salvar">
                    <OrcamentoSheet :orcamento-id="orcamento?.id" :emitido-em="new Date().toLocaleDateString('pt-BR')">
                        <template #cliente-busca>
                            <ClienteBuscaBar @selecionar="selecionarCliente" />
                        </template>

                        <template #info-cliente>
                            <div class="flex flex-col gap-2 text-sm">
                                <div>
                                    <label class="text-xs text-gray-500">Nome / Razão Social *</label>
                                    <input v-model="form.cliente_nome" type="text" required class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                    <InputError :message="form.errors.cliente_nome" />
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">CNPJ</label>
                                        <input v-model="form.cliente_cnpj" type="text" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                        <InputError :message="form.errors.cliente_cnpj" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Contato / Telefone</label>
                                        <input v-model="form.cliente_contato" type="text" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                        <InputError :message="form.errors.cliente_contato" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template #info-orcamento>
                            <div class="flex flex-col gap-2 text-sm">
                                <div>
                                    <label class="text-xs text-gray-500">Forma de Pagamento</label>
                                    <select
                                        v-if="!modoFormaPagamentoLivre"
                                        :value="form.forma_pagamento"
                                        class="mt-0.5 block w-full rounded border-gray-300 text-sm"
                                        @change="selecionarFormaPagamento($event.target.value)"
                                    >
                                        <option value="">Selecione</option>
                                        <option v-for="opcao in OPCOES_FIXAS" :key="opcao" :value="opcao">{{ opcao }}</option>
                                        <option value="__livre__">Outros</option>
                                    </select>
                                    <div v-else class="mt-0.5 flex gap-2">
                                        <input v-model="form.forma_pagamento" type="text" placeholder="Descreva a forma de pagamento" class="block w-full rounded border-gray-300 text-sm" />
                                        <button type="button" class="whitespace-nowrap text-xs text-gray-500 underline" @click="modoFormaPagamentoLivre = false; form.forma_pagamento = ''">voltar</button>
                                    </div>
                                    <InputError :message="form.errors.forma_pagamento" />
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500">Frete</label>
                                    <div class="mt-0.5 flex gap-4">
                                        <label v-for="(rotulo, valor) in ROTULOS_TIPO_FRETE" :key="valor" class="flex items-center gap-1.5 text-sm">
                                            <input v-model="form.tipo_frete" type="radio" :value="valor" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ rotulo }}
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.tipo_frete" />
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500">Faturamento</label>
                                    <div class="mt-0.5 flex gap-4">
                                        <label class="flex items-center gap-1.5 text-sm">
                                            <input v-model="form.tipo_produto_servico" type="radio" value="produto" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ ROTULOS_TIPO_PRODUTO_SERVICO.produto }}
                                        </label>
                                        <label class="flex items-center gap-1.5 text-sm">
                                            <input v-model="form.tipo_produto_servico" type="radio" value="servico" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ ROTULOS_TIPO_PRODUTO_SERVICO.servico }}
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.tipo_produto_servico" />
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500">Validade</label>
                                    <input v-model="form.data_validade" type="date" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                    <InputError :message="form.errors.data_validade" />
                                </div>
                            </div>
                        </template>

                        <template #itens>
                            <ItensTabela
                                v-model="form.itens"
                                :tipo-produto-servico="form.tipo_produto_servico"
                                :is-admin="isAdmin"
                                :materias-primas="materiasPrimas"
                                :errors="form.errors"
                            />
                            <InputError :message="form.errors.itens" class="mt-1" />

                            <div class="mt-4 flex flex-wrap gap-2">
                                <div class="min-w-[140px] flex-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-center">
                                    <p class="text-xs uppercase text-gray-400">Subtotal s/IPI</p>
                                    <p class="font-bold text-navy">{{ formatBRL(resumo.subtotalProdutosSemIpi) }}</p>
                                </div>
                                <div v-if="form.tipo_produto_servico === 'produto'" class="min-w-[140px] flex-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-center">
                                    <p class="text-xs uppercase text-gray-400">Subtotal c/IPI</p>
                                    <p class="font-bold text-navy">{{ formatBRL(resumo.subtotalProdutosComIpi) }}</p>
                                </div>
                                <div v-if="resumo.subtotalEtiquetas > 0" class="min-w-[140px] flex-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-center">
                                    <p class="text-xs uppercase text-gray-400">Subtotal etiquetas</p>
                                    <p class="font-bold text-navy">{{ formatBRL(resumo.subtotalEtiquetas) }}</p>
                                </div>
                                <div class="min-w-[140px] flex-1 rounded border border-cyan/40 bg-cyan/5 px-3 py-2 text-center">
                                    <p class="text-xs uppercase text-gray-500">Valor Total</p>
                                    <p class="text-lg font-bold text-navy">{{ formatBRL(resumo.totalGeral) }}</p>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-400">
                                O nível de aprovação (nenhum / supervisor / diretor) é sempre recalculado pelo servidor, com base no desconto sem IPI.
                            </p>
                        </template>

                        <template #observacoes>
                            <textarea v-model="form.observacoes" rows="2" class="block w-full rounded border-gray-300 text-sm" />
                        </template>

                        <template #outras-informacoes>
                            <div class="flex flex-col gap-2 text-sm">
                                <div>
                                    <label class="text-xs text-gray-500">Variação nos produtos Personalizados</label>
                                    <input v-model="form.variacao_producao_personalizado" type="text" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-gray-500">Prazo de PRODUÇÃO</label>
                                        <input v-model="form.prazo_producao" type="text" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500">Garantia de Imagem</label>
                                        <input v-model="form.garantia_imagem" type="text" class="mt-0.5 block w-full rounded border-gray-300 text-sm" />
                                    </div>
                                </div>
                                <div class="rounded border border-amber/40 bg-amber/5 p-2">
                                    <label class="text-xs font-bold uppercase text-amber">Importante</label>
                                    <textarea v-model="form.texto_importante" rows="2" class="mt-0.5 block w-full rounded border-amber/30 text-sm" />
                                </div>
                            </div>
                        </template>
                    </OrcamentoSheet>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
