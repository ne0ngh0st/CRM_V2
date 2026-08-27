<script setup>
import { router, Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import SortableTh from '@/Components/Tabela/SortableTh.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira.js';

defineProps({
    clientes: { type: Array, required: true },
    ordenar: { type: String, default: '' },
    podeVerDetalhes: { type: Boolean, default: false },
    podeLigar: { type: Boolean, default: false },
    podeAgendar: { type: Boolean, default: false },
    podeOrcamento: { type: Boolean, default: false },
    podeObservar: { type: Boolean, default: false },
});

const emit = defineEmits(['motivo-inatividade', 'observacao', 'agendar-ligacao', 'ordenar']);

function formatarTelefone(telefone) {
    let numero = telefone.replace(/\D/g, '').replace(/^0+/, '');
    if ((numero.length === 10 || numero.length === 11) && !numero.startsWith('0')) {
        numero = `0${numero}`;
    }

    return numero;
}

function ligar(cliente) {
    if (!cliente.telefone) return;

    router.post(route('carteira.ligacao', cliente.id), {}, { preserveScroll: true, preserveState: true });

    const numero = formatarTelefone(cliente.telefone);
    const ehMobile = /Mobi|Android/i.test(navigator.userAgent);
    window.location.href = ehMobile ? `tel:${numero}` : `callto:${numero}`;
}

function criarOrcamento(cliente) {
    router.get(route('orcamentos.novo'), {
        cliente_nome: cliente.razaoSocial,
        cliente_cnpj: cliente.cnpj ?? '',
        cliente_contato: cliente.telefone ?? '',
    });
}
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl min-w-[1000px]">
            <thead>
                <tr class="tbl-head-row">
                    <SortableTh campo="nome" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Cliente</SortableTh>
                    <SortableTh campo="grupo" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Grupo</SortableTh>
                    <SortableTh campo="vendedor" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Vendedor</SortableTh>
                    <SortableTh campo="estado" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Estado</SortableTh>
                    <SortableTh campo="segmento" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Segmento</SortableTh>
                    <SortableTh campo="status" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Status</SortableTh>
                    <SortableTh campo="ultima_compra" :ordenar="ordenar" @ordenar="emit('ordenar', $event)">Última Compra</SortableTh>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="cliente in clientes" :key="cliente.id" class="tbl-row">
                    <td class="tbl-td">
                        <span class="tbl-main mx-auto max-w-[220px]" :title="cliente.razaoSocial">{{ cliente.razaoSocial }}</span>
                        <span class="tbl-sub">{{ cliente.cnpj ?? '—' }}</span>
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[180px]" :title="cliente.grupo ?? ''">{{ cliente.grupo ?? '—' }}</span>
                    </td>
                    <td class="tbl-td">{{ cliente.vendedorNome }}</td>
                    <td class="tbl-td">{{ cliente.estado ?? '—' }}</td>
                    <td class="tbl-td">{{ cliente.segmento ?? '—' }}</td>
                    <td class="tbl-td">
                        <button
                            v-if="cliente.status === 'inativo'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border-0 bg-transparent p-0 transition hover:ring-2 hover:ring-red-300"
                            :title="cliente.motivoInatividade ? `Motivo: ${cliente.motivoInatividade.motivo}` : 'Motivo de inatividade pendente — clique pra registrar'"
                            @click="emit('motivo-inatividade', cliente)"
                        >
                            <StatusPill tone="danger" size="sm">
                                {{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}
                                <svg v-if="!cliente.motivoInatividade" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5">
                                    <path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5">
                                    <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </StatusPill>
                        </button>
                        <StatusPill v-else :tone="TONS_STATUS_CARTEIRA[cliente.status]" size="sm">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
                    </td>
                    <td class="tbl-td">{{ cliente.dataUltimaCompra ?? 'Nunca' }}</td>
                    <td class="tbl-td">
                        <div class="tbl-acoes">
                            <Link
                                v-if="podeVerDetalhes"
                                :href="route('carteira.detalhes', cliente.id)"
                                title="Ver detalhes"
                                class="tbl-acao tbl-acao-neutro"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </Link>
                            <button
                                v-if="podeLigar"
                                type="button"
                                :disabled="!cliente.telefone"
                                :title="cliente.telefone ? 'Realizar ligação' : 'Telefone não cadastrado'"
                                class="tbl-acao tbl-acao-verde"
                                @click="ligar(cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 5c0 8.284 6.716 15 15 15 1-1.5 1.5-3 1.5-4.5l-4-1.5-1.5 2A11.5 11.5 0 0 1 9 10.5l2-1.5-1.5-4C8 5 6.5 5.5 5 6.5 4.5 5.5 4 5.5 4 5Z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeAgendar"
                                type="button"
                                title="Agendar ligação"
                                class="tbl-acao tbl-acao-cyan"
                                @click="emit('agendar-ligacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="4.5" width="18" height="16" rx="1.5" />
                                    <path d="M3 9h18M8 3v3M16 3v3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="m9 15 2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeOrcamento"
                                type="button"
                                title="Criar orçamento"
                                class="tbl-acao tbl-acao-navy"
                                @click="criarOrcamento(cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M7 3h7l4 4v14H7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="M14 3v4h4M9.5 13h5M9.5 16.5h5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeObservar"
                                type="button"
                                title="Observações"
                                class="tbl-acao tbl-acao-amber"
                                @click="emit('observacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <line x1="8" y1="9" x2="16" y2="9" stroke-linecap="round" />
                                    <line x1="8" y1="12.5" x2="13" y2="12.5" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
