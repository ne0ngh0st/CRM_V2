<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatusPill from '@/Components/StatusPill.vue';
import OrcamentoSheet from '@/Components/Orcamentos/OrcamentoSheet.vue';
import DocPainel from '@/Components/Orcamentos/DocPainel.vue';
import DocLinha from '@/Components/Orcamentos/DocLinha.vue';
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

const usuario = usePage().props.auth.user;
const vendedor = {
    nome: usuario?.display_name || usuario?.name || '',
    telefone: usuario?.telefone || '',
    email: usuario?.email || '',
};
const emitidoEm = new Date().toLocaleDateString('pt-BR');

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

                <!--
                    Nota de bastidor: fica FORA da folha de propósito. Tudo que está dentro
                    da folha é exatamente o que o cliente recebe; aprovação é assunto interno.
                -->
                <p class="mx-auto w-full max-w-[1180px] border-l-2 border-gray-300 bg-gray-50 px-3 py-1.5 text-[0.7rem] text-gray-500">
                    Uso interno: o nível de aprovação (nenhum / supervisor / diretor) é sempre recalculado pelo servidor
                    com base no desconto sem IPI, e não aparece no documento enviado ao cliente.
                </p>

                <form id="form-orcamento" @submit.prevent="salvar">
                    <OrcamentoSheet
                        :orcamento-id="orcamento?.id"
                        :emitido-em="emitidoEm"
                        :vendedor="vendedor"
                        :resumo="resumo"
                        :tipo-produto-servico="form.tipo_produto_servico"
                    >
                        <template #cliente-busca>
                            <ClienteBuscaBar @selecionar="selecionarCliente" />
                        </template>

                        <template #info-cliente>
                            <DocPainel titulo="Dados do cliente">
                                <DocLinha rotulo="Razão social">
                                    <input v-model="form.cliente_nome" type="text" required class="doc-campo" placeholder="Nome ou razão social" />
                                    <InputError :message="form.errors.cliente_nome" />
                                </DocLinha>
                                <DocLinha rotulo="CNPJ">
                                    <input v-model="form.cliente_cnpj" type="text" class="doc-campo" placeholder="00.000.000/0000-00" />
                                    <InputError :message="form.errors.cliente_cnpj" />
                                </DocLinha>
                                <DocLinha rotulo="Contato">
                                    <input v-model="form.cliente_contato" type="text" class="doc-campo" placeholder="Telefone ou e-mail" />
                                    <InputError :message="form.errors.cliente_contato" />
                                </DocLinha>
                            </DocPainel>
                        </template>

                        <template #info-proposta>
                            <DocPainel titulo="Dados da proposta">
                                <DocLinha rotulo="Emissão">
                                    <span class="doc-valor">{{ emitidoEm }}</span>
                                </DocLinha>
                                <DocLinha rotulo="Validade">
                                    <input v-model="form.data_validade" type="date" class="doc-campo" />
                                    <InputError :message="form.errors.data_validade" />
                                </DocLinha>
                                <DocLinha rotulo="Forma de pagamento">
                                    <select
                                        v-if="!modoFormaPagamentoLivre"
                                        :value="form.forma_pagamento"
                                        class="doc-campo"
                                        @change="selecionarFormaPagamento($event.target.value)"
                                    >
                                        <option value="">Selecione</option>
                                        <option v-for="opcao in OPCOES_FIXAS" :key="opcao" :value="opcao">{{ opcao }}</option>
                                        <option value="__livre__">Outros</option>
                                    </select>
                                    <div v-else class="flex items-center gap-2">
                                        <input v-model="form.forma_pagamento" type="text" placeholder="Descreva a forma de pagamento" class="doc-campo" />
                                        <button type="button" class="whitespace-nowrap text-[0.65rem] text-gray-400 underline" @click="modoFormaPagamentoLivre = false; form.forma_pagamento = ''">
                                            voltar
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.forma_pagamento" />
                                </DocLinha>
                                <DocLinha rotulo="Frete">
                                    <div class="flex flex-col gap-1">
                                        <label v-for="(rotulo, valor) in ROTULOS_TIPO_FRETE" :key="valor" class="flex items-center gap-1.5 text-[0.8rem]">
                                            <input v-model="form.tipo_frete" type="radio" :value="valor" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ rotulo }}
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.tipo_frete" />
                                </DocLinha>
                                <DocLinha rotulo="Faturamento">
                                    <div class="flex flex-col gap-1">
                                        <label class="flex items-center gap-1.5 text-[0.8rem]">
                                            <input v-model="form.tipo_produto_servico" type="radio" value="produto" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ ROTULOS_TIPO_PRODUTO_SERVICO.produto }}
                                        </label>
                                        <label class="flex items-center gap-1.5 text-[0.8rem]">
                                            <input v-model="form.tipo_produto_servico" type="radio" value="servico" class="border-gray-300 text-cyan focus:ring-cyan" />
                                            {{ ROTULOS_TIPO_PRODUTO_SERVICO.servico }}
                                        </label>
                                    </div>
                                    <InputError :message="form.errors.tipo_produto_servico" />
                                </DocLinha>
                                <DocLinha rotulo="Vendedor">
                                    <span class="doc-valor">{{ vendedor.nome || '—' }}</span>
                                    <p v-if="vendedor.telefone || vendedor.email" class="text-[0.7rem] font-normal text-gray-500">
                                        {{ [vendedor.telefone, vendedor.email].filter(Boolean).join(' · ') }}
                                    </p>
                                </DocLinha>
                            </DocPainel>
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
                        </template>

                        <template #observacoes>
                            <textarea
                                v-model="form.observacoes"
                                rows="2"
                                class="doc-campo-bloco"
                                placeholder="Texto livre que sai impresso para o cliente. Em branco, a seção não aparece no PDF."
                            />
                        </template>

                        <template #condicoes>
                            <div class="space-y-2">
                                <div class="grid grid-cols-1 items-center gap-x-2 gap-y-1 sm:grid-cols-[34%_1fr]">
                                    <span class="doc-rotulo">Variação em produtos personalizados</span>
                                    <input v-model="form.variacao_producao_personalizado" type="text" class="doc-campo" />
                                </div>
                                <div class="grid grid-cols-1 items-center gap-x-2 gap-y-1 sm:grid-cols-[34%_1fr]">
                                    <span class="doc-rotulo">Prazo de produção</span>
                                    <input v-model="form.prazo_producao" type="text" class="doc-campo" />
                                </div>
                                <div class="grid grid-cols-1 items-center gap-x-2 gap-y-1 sm:grid-cols-[34%_1fr]">
                                    <span class="doc-rotulo">Garantia de imagem</span>
                                    <input v-model="form.garantia_imagem" type="text" class="doc-campo" />
                                </div>
                            </div>

                            <div class="mt-3 border border-amber/60 bg-amber/5 px-3 py-2">
                                <p class="text-[0.65rem] font-bold uppercase tracking-[0.08em] text-amber-dark">Importante</p>
                                <textarea v-model="form.texto_importante" rows="2" class="doc-campo-bloco mt-1 border-amber/40" />
                            </div>
                        </template>
                    </OrcamentoSheet>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
