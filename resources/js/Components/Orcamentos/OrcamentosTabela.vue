<script setup>
import { ref } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import {
    ROTULOS_STATUS_ORCAMENTO,
    TONS_STATUS_ORCAMENTO,
    ROTULOS_NIVEL_APROVACAO,
    ROTULOS_VALIDADE_ORCAMENTO,
    TONS_VALIDADE_ORCAMENTO,
} from '@/constants/orcamentos.js';

const props = defineProps({
    orcamentos: { type: Array, required: true },
    podeExcluir: { type: Boolean, default: false },
});

defineEmits(['editar', 'copiar', 'aprovar', 'rejeitar', 'excluir']);

const expandido = ref(null);

function toggle(id) {
    expandido.value = expandido.value === id ? null : id;
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function formatQuantidade(valor) {
    return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(valor);
}

function pdfUrl(orcamento, download) {
    return route('orcamentos.pdf', orcamento.id) + (download ? '?download=1' : '');
}
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl min-w-[1000px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th w-8"></th>
                    <th class="tbl-th">Cliente</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">Valor Total</th>
                    <th class="tbl-th">Nível</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Validade</th>
                    <th class="tbl-th">Criado em</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="orcamento in orcamentos" :key="orcamento.id">
                    <tr class="tbl-row cursor-pointer" @click="toggle(orcamento.id)">
                        <td class="tbl-td text-gray-400">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="mx-auto h-3.5 w-3.5 transition-transform"
                                :class="expandido === orcamento.id ? 'rotate-90' : ''"
                            >
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </td>
                        <td class="tbl-td">
                            <span class="tbl-main mx-auto max-w-[220px]" :title="orcamento.clienteNome">{{ orcamento.clienteNome }}</span>
                            <span class="tbl-sub">{{ orcamento.clienteCnpj ?? '—' }}</span>
                        </td>
                        <td class="tbl-td">{{ orcamento.vendedorNome }}</td>
                        <td class="tbl-td font-medium text-gray-800">{{ formatBRL(orcamento.valorTotal) }}</td>
                        <td class="tbl-td">
                            <StatusPill :tone="orcamento.nivelAprovacao === 'diretor' ? 'danger' : orcamento.nivelAprovacao === 'supervisor' ? 'warn' : 'neutral'" size="sm">
                                {{ ROTULOS_NIVEL_APROVACAO[orcamento.nivelAprovacao] }}
                            </StatusPill>
                        </td>
                        <td class="tbl-td">
                            <StatusPill :tone="TONS_STATUS_ORCAMENTO[orcamento.statusGestor]" size="sm">{{ ROTULOS_STATUS_ORCAMENTO[orcamento.statusGestor] }}</StatusPill>
                        </td>
                        <td class="tbl-td">
                            <StatusPill :tone="TONS_VALIDADE_ORCAMENTO[orcamento.validadeSituacao]" size="sm">
                                {{ orcamento.dataValidadeFormatada ?? ROTULOS_VALIDADE_ORCAMENTO.sem_validade }}
                            </StatusPill>
                        </td>
                        <td class="tbl-td">{{ orcamento.criadoEm }}</td>
                        <td class="tbl-td" @click.stop>
                            <div class="tbl-acoes">
                                <button
                                    v-if="orcamento.podeDecidir"
                                    type="button"
                                    title="Aprovar"
                                    class="tbl-acao tbl-acao-verde"
                                    @click="$emit('aprovar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8 12.5 2.5 2.5L16 9" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="orcamento.podeDecidir"
                                    type="button"
                                    title="Rejeitar"
                                    class="tbl-acao tbl-acao-danger"
                                    @click="$emit('rejeitar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m9 9 6 6M15 9l-6 6" stroke-linecap="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="orcamento.podeEditar"
                                    type="button"
                                    title="Editar"
                                    class="tbl-acao tbl-acao-teal"
                                    @click="$emit('editar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    title="Copiar orçamento"
                                    class="tbl-acao tbl-acao-neutro"
                                    @click="$emit('copiar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="9" y="9" width="11" height="11" rx="1" />
                                        <path d="M5 15V5a1 1 0 0 1 1-1h10" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <a
                                    :href="pdfUrl(orcamento)"
                                    target="_blank"
                                    title="Ver PDF"
                                    class="tbl-acao tbl-acao-navy"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M14 3v4h4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                                <button
                                    v-if="podeExcluir"
                                    type="button"
                                    title="Excluir"
                                    class="tbl-acao tbl-acao-danger"
                                    @click="$emit('excluir', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="expandido === orcamento.id" class="bg-gray-50">
                        <td colspan="9" class="p-4">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="lg:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do orçamento</p>
                                    <table class="tbl-itens">
                                        <thead>
                                            <tr class="tbl-itens-head-row">
                                                <th class="tbl-itens-th">Produto</th>
                                                <th class="tbl-itens-th">Qtd.</th>
                                                <th class="tbl-itens-th">Vlr. Unit.</th>
                                                <th class="tbl-itens-th">Preço Tabela</th>
                                                <th class="tbl-itens-th">Vlr. Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="tbl-body">
                                            <tr v-for="item in orcamento.itens" :key="item.id" class="tbl-itens-row">
                                                <td class="tbl-itens-td">
                                                    <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                </td>
                                                <td class="tbl-itens-td">{{ formatQuantidade(item.quantidade) }}</td>
                                                <td class="tbl-itens-td">{{ formatBRL(item.valorUnitario) }}</td>
                                                <td class="tbl-itens-td">{{ item.precoTabela !== null ? formatBRL(item.precoTabela) : '—' }}</td>
                                                <td class="tbl-itens-td font-medium text-gray-800">{{ formatBRL(item.valorTotal) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Detalhes</p>
                                    <dl class="mt-2 space-y-1.5 text-xs text-gray-600">
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Contato</dt>
                                            <dd class="text-right">{{ orcamento.clienteContato ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Forma pagto.</dt>
                                            <dd class="text-right">{{ orcamento.formaPagamento ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Desconto máx.</dt>
                                            <dd class="text-right">{{ orcamento.descontoPctMax.toFixed(2) }}%</dd>
                                        </div>
                                        <div v-if="orcamento.aprovadoPorNome" class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Decidido por</dt>
                                            <dd class="text-right">{{ orcamento.aprovadoPorNome }} · {{ orcamento.aprovadoEm }}</dd>
                                        </div>
                                        <div v-if="orcamento.motivoRejeicao" class="flex flex-col gap-1">
                                            <dt class="text-gray-400">Motivo da rejeição</dt>
                                            <dd class="text-red-600">{{ orcamento.motivoRejeicao }}</dd>
                                        </div>
                                    </dl>
                                    <a
                                        :href="pdfUrl(orcamento, true)"
                                        class="mt-3 inline-block text-xs font-medium text-cyan hover:underline"
                                    >
                                        Baixar PDF
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
