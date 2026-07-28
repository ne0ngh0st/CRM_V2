<script setup>
import { router } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_LEAD, TONS_STATUS_LEAD, ROTULOS_ORIGEM_LEAD } from '@/constants/leads.js';

defineProps({
    leads: { type: Array, required: true },
    podeLigar: { type: Boolean, default: false },
    podeAgendar: { type: Boolean, default: false },
    podeOrcamento: { type: Boolean, default: false },
    podeObservar: { type: Boolean, default: false },
    podeExcluir: { type: Boolean, default: false },
});

const emit = defineEmits(['observacao', 'agendar-ligacao']);

function formatBRL(valor) {
    if (valor === null || valor === undefined) return '—';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function formatarTelefone(telefone) {
    let numero = String(telefone).replace(/\D/g, '').replace(/^0+/, '');
    if ((numero.length === 10 || numero.length === 11) && !numero.startsWith('0')) {
        numero = `0${numero}`;
    }
    return numero;
}

function ligar(lead) {
    if (!lead.telefone) return;
    router.post(route('leads.ligacao', lead.id), {}, { preserveScroll: true, preserveState: true });
    const numero = formatarTelefone(lead.telefone);
    const ehMobile = /Mobi|Android/i.test(navigator.userAgent);
    window.location.href = ehMobile ? `tel:${numero}` : `callto:${numero}`;
}

function criarOrcamento(lead) {
    router.get(route('orcamentos.novo'), {
        cliente_nome: lead.razaoSocial || lead.nome,
        cliente_cnpj: lead.cnpj ?? '',
        cliente_contato: lead.telefone ?? '',
    });
}

function excluir(lead) {
    if (!confirm(`Excluir o lead "${lead.razaoSocial || lead.nome}"?`)) return;
    router.delete(route('leads.destroy', lead.id), { preserveScroll: true });
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="px-3 py-2.5 font-semibold">Lead</th>
                    <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                    <th class="px-3 py-2.5 font-semibold">UF / Cidade</th>
                    <th class="px-3 py-2.5 font-semibold">Segmento</th>
                    <th class="px-3 py-2.5 font-semibold">Origem</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Valor est.</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr v-for="lead in leads" :key="lead.id" class="divide-x divide-gray-200 hover:bg-gray-50/60">
                    <td class="px-3 py-2.5 text-center align-middle">
                        <span class="mx-auto block max-w-[220px] truncate font-semibold text-gray-800" :title="lead.razaoSocial">
                            {{ lead.razaoSocial || lead.nome }}
                        </span>
                        <span class="block text-xs text-gray-400">{{ lead.cnpj || lead.email || '—' }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ lead.vendedorNome || '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">
                        <span v-if="lead.estado || lead.cidade">{{ lead.estado || '—' }}{{ lead.cidade ? ` · ${lead.cidade}` : '' }}</span>
                        <span v-else>—</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ lead.segmento || '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <StatusPill :tone="lead.origem === 'manual' ? 'warn' : 'neutral'">
                            {{ ROTULOS_ORIGEM_LEAD[lead.origem] || lead.origem }}
                        </StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <StatusPill :tone="TONS_STATUS_LEAD[lead.status] || 'neutral'">
                            {{ ROTULOS_STATUS_LEAD[lead.status] || lead.status }}
                        </StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ formatBRL(lead.valorEstimado) }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
                            <button
                                v-if="podeLigar"
                                type="button"
                                title="Realizar ligação"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:text-teal"
                                :disabled="!lead.telefone"
                                @click="ligar(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M5 4h4l2 5-2.5 1.5a12 12 0 006 6L16 14l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeAgendar"
                                type="button"
                                title="Agendar ligação"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-amber hover:text-amber"
                                @click="emit('agendar-ligacao', lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <rect x="3" y="5" width="18" height="16" rx="1" />
                                    <path d="M3 10h18M8 3v4M16 3v4" stroke-linecap="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeOrcamento"
                                type="button"
                                title="Criar orçamento"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-cyan hover:text-cyan"
                                @click="criarOrcamento(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M8 6h11M8 12h11M8 18h8M4 6h.01M4 12h.01M4 18h.01" stroke-linecap="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeObservar"
                                type="button"
                                title="Observações"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-navy hover:text-navy"
                                @click="emit('observacao', lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M4 5h16v12H8l-4 4V5z" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeExcluir"
                                type="button"
                                title="Excluir lead"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-red-400 hover:text-red-600"
                                @click="excluir(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M5 7h14M10 11v6M14 11v6M9 7l1-2h4l1 2M8 7l1 12h6l1-12" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
