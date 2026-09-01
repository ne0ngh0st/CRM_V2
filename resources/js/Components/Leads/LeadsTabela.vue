<script setup>
import { router } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_LEAD, TONS_STATUS_LEAD, ROTULOS_ORIGEM_LEAD, TONS_ORIGEM_LEAD } from '@/constants/leads.js';

defineProps({
    leads: { type: Array, required: true },
    podeLigar: { type: Boolean, default: false },
    podeAgendar: { type: Boolean, default: false },
    podeOrcamento: { type: Boolean, default: false },
    podeObservar: { type: Boolean, default: false },
    podeExcluir: { type: Boolean, default: false },
});

const emit = defineEmits(['observacao', 'agendar-ligacao', 'captura']);

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
    <div class="tbl-wrap">
        <table class="tbl min-w-[1000px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Lead</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">UF / Cidade</th>
                    <th class="tbl-th">Segmento</th>
                    <th class="tbl-th">Origem</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Valor est.</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="lead in leads" :key="lead.id" class="tbl-row">
                    <td class="tbl-td">
                        <span class="tbl-main mx-auto max-w-[220px]" :title="lead.razaoSocial">
                            {{ lead.razaoSocial || lead.nome }}
                        </span>
                        <span class="tbl-sub">{{ lead.cnpj || lead.email || '—' }}</span>
                        <span v-if="lead.formularioNome" class="tbl-sub">{{ lead.formularioNome }}</span>
                    </td>
                    <td class="tbl-td">{{ lead.vendedorNome || '—' }}</td>
                    <td class="tbl-td">
                        <span v-if="lead.estado || lead.cidade">{{ lead.estado || '—' }}{{ lead.cidade ? ` · ${lead.cidade}` : '' }}</span>
                        <span v-else>—</span>
                    </td>
                    <td class="tbl-td">{{ lead.segmento || '—' }}</td>
                    <td class="tbl-td">
                        <StatusPill :tone="TONS_ORIGEM_LEAD[lead.origem] || 'neutral'" size="sm">
                            {{ ROTULOS_ORIGEM_LEAD[lead.origem] || lead.origem }}
                        </StatusPill>
                    </td>
                    <td class="tbl-td">
                        <StatusPill :tone="TONS_STATUS_LEAD[lead.status] || 'neutral'" size="sm">
                            {{ ROTULOS_STATUS_LEAD[lead.status] || lead.status }}
                        </StatusPill>
                    </td>
                    <td class="tbl-td">{{ formatBRL(lead.valorEstimado) }}</td>
                    <td class="tbl-td">
                        <div class="tbl-acoes">
                            <button
                                v-if="lead.temCaptura"
                                type="button"
                                title="Ver payload da captura"
                                class="tbl-acao tbl-acao-neutro"
                                @click="emit('captura', lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 6h16M4 12h10M4 18h16" stroke-linecap="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeLigar"
                                type="button"
                                title="Realizar ligação"
                                class="tbl-acao tbl-acao-verde"
                                :disabled="!lead.telefone"
                                @click="ligar(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M5 4h4l2 5-2.5 1.5a12 12 0 006 6L16 14l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeAgendar"
                                type="button"
                                title="Agendar ligação"
                                class="tbl-acao tbl-acao-cyan"
                                @click="emit('agendar-ligacao', lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="5" width="18" height="16" rx="1" />
                                    <path d="M3 10h18M8 3v4M16 3v4" stroke-linecap="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeOrcamento"
                                type="button"
                                title="Criar orçamento"
                                class="tbl-acao tbl-acao-navy"
                                @click="criarOrcamento(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M8 6h11M8 12h11M8 18h8M4 6h.01M4 12h.01M4 18h.01" stroke-linecap="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeObservar"
                                type="button"
                                title="Observações"
                                class="tbl-acao tbl-acao-amber"
                                @click="emit('observacao', lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 5h16v12H8l-4 4V5z" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeExcluir"
                                type="button"
                                title="Excluir lead"
                                class="tbl-acao tbl-acao-danger"
                                @click="excluir(lead)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
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
