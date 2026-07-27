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

defineEmits(['editar', 'aprovar', 'rejeitar', 'excluir']);

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
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="w-8 px-2 py-2.5"></th>
                    <th class="px-3 py-2.5 font-semibold">Cliente</th>
                    <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                    <th class="px-3 py-2.5 font-semibold">Valor Total</th>
                    <th class="px-3 py-2.5 font-semibold">Nível</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Validade</th>
                    <th class="px-3 py-2.5 font-semibold">Criado em</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <template v-for="orcamento in orcamentos" :key="orcamento.id">
                    <tr class="cursor-pointer divide-x divide-gray-200 hover:bg-gray-50/60" @click="toggle(orcamento.id)">
                        <td class="px-2 py-2.5 text-center align-middle text-gray-400">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="mx-auto h-3.5 w-3.5 transition-transform"
                                :class="expandido === orcamento.id ? 'rotate-90' : ''"
                            >
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-700">
                            <span class="mx-auto block max-w-[220px] truncate font-semibold text-gray-800" :title="orcamento.clienteNome">{{ orcamento.clienteNome }}</span>
                            <span class="block text-xs text-gray-400">{{ orcamento.clienteCnpj ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ orcamento.vendedorNome }}</td>
                        <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ formatBRL(orcamento.valorTotal) }}</td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="orcamento.nivelAprovacao === 'diretor' ? 'danger' : orcamento.nivelAprovacao === 'supervisor' ? 'warn' : 'neutral'">
                                {{ ROTULOS_NIVEL_APROVACAO[orcamento.nivelAprovacao] }}
                            </StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="TONS_STATUS_ORCAMENTO[orcamento.statusGestor]">{{ ROTULOS_STATUS_ORCAMENTO[orcamento.statusGestor] }}</StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="TONS_VALIDADE_ORCAMENTO[orcamento.validadeSituacao]">
                                {{ orcamento.dataValidadeFormatada ?? ROTULOS_VALIDADE_ORCAMENTO.sem_validade }}
                            </StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-500">{{ orcamento.criadoEm }}</td>
                        <td class="px-3 py-2.5 text-center align-middle" @click.stop>
                            <div class="flex flex-wrap items-center justify-center gap-1.5">
                                <button
                                    v-if="orcamento.podeDecidir"
                                    type="button"
                                    title="Aprovar"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-600"
                                    @click="$emit('aprovar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m8 12.5 2.5 2.5L16 9" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="orcamento.podeDecidir"
                                    type="button"
                                    title="Rejeitar"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-red-400 hover:bg-red-50 hover:text-red-500"
                                    @click="$emit('rejeitar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <circle cx="12" cy="12" r="9" />
                                        <path d="m9 9 6 6M15 9l-6 6" stroke-linecap="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="orcamento.podeEditar"
                                    type="button"
                                    title="Editar"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:bg-teal/10 hover:text-teal"
                                    @click="$emit('editar', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <a
                                    :href="pdfUrl(orcamento)"
                                    target="_blank"
                                    title="Ver PDF"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-cyan hover:bg-cyan/10 hover:text-cyan"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M14 3v4h4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                                <button
                                    v-if="podeExcluir"
                                    type="button"
                                    title="Excluir"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-red-400 hover:bg-red-50 hover:text-red-500"
                                    @click="$emit('excluir', orcamento)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
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
                                    <table class="mt-2 w-full overflow-hidden rounded border border-gray-200 text-xs">
                                        <thead>
                                            <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-100 text-center text-gray-500">
                                                <th class="px-2 py-1.5 font-semibold">Produto</th>
                                                <th class="px-2 py-1.5 font-semibold">Qtd.</th>
                                                <th class="px-2 py-1.5 font-semibold">Vlr. Unit.</th>
                                                <th class="px-2 py-1.5 font-semibold">Preço Tabela</th>
                                                <th class="px-2 py-1.5 font-semibold">Vlr. Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <tr v-for="item in orcamento.itens" :key="item.id" class="divide-x divide-gray-200">
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-700">
                                                    <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                </td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">{{ formatQuantidade(item.quantidade) }}</td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">{{ formatBRL(item.valorUnitario) }}</td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">{{ item.precoTabela !== null ? formatBRL(item.precoTabela) : '—' }}</td>
                                                <td class="px-2 py-1.5 text-center align-middle font-medium text-gray-800">{{ formatBRL(item.valorTotal) }}</td>
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
