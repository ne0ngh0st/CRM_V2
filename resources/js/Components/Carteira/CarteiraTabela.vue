<script setup>
import { router, Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA, ROTULOS_ADERENCIA } from '@/constants/carteira.js';

defineProps({
    clientes: { type: Array, required: true },
    podeVerDetalhes: { type: Boolean, default: false },
    podeLigar: { type: Boolean, default: false },
    podeAgendar: { type: Boolean, default: false },
    podeOrcamento: { type: Boolean, default: false },
    podeObservar: { type: Boolean, default: false },
});

const emit = defineEmits(['motivo-inatividade', 'observacao', 'agendar-ligacao']);

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
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="px-3 py-2.5 font-semibold">Cliente</th>
                    <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                    <th class="px-3 py-2.5 font-semibold">Estado</th>
                    <th class="px-3 py-2.5 font-semibold">Segmento</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Aderência</th>
                    <th class="px-3 py-2.5 font-semibold">Última Compra</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr v-for="cliente in clientes" :key="cliente.id" class="divide-x divide-gray-200 hover:bg-gray-50/60">
                    <td class="px-3 py-2.5 text-center align-middle">
                        <span class="mx-auto block max-w-[220px] truncate font-semibold text-gray-800" :title="cliente.razaoSocial">{{ cliente.razaoSocial }}</span>
                        <span class="block text-xs text-gray-400">{{ cliente.cnpj ?? '—' }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ cliente.vendedorNome }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.estado ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.segmento ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <button
                            v-if="cliente.status === 'inativo'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border-0 bg-transparent p-0 transition hover:ring-2 hover:ring-red-300"
                            :title="cliente.motivoInatividade ? `Motivo: ${cliente.motivoInatividade.motivo}` : 'Motivo de inatividade pendente — clique pra registrar'"
                            @click="emit('motivo-inatividade', cliente)"
                        >
                            <StatusPill tone="danger">
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
                        <StatusPill v-else :tone="TONS_STATUS_CARTEIRA[cliente.status]">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <StatusPill :tone="cliente.aderencia === 'dentro' ? 'ok' : 'neutral'">{{ ROTULOS_ADERENCIA[cliente.aderencia] }}</StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.dataUltimaCompra ?? 'Nunca' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
                            <Link
                                v-if="podeVerDetalhes"
                                :href="route('carteira.detalhes', cliente.id)"
                                title="Ver detalhes"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-gray-400 hover:bg-gray-100 hover:text-gray-700"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </Link>
                            <button
                                v-if="podeLigar"
                                type="button"
                                :disabled="!cliente.telefone"
                                :title="cliente.telefone ? 'Realizar ligação' : 'Telefone não cadastrado'"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:bg-teal/10 hover:text-teal disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-gray-200 disabled:hover:bg-transparent disabled:hover:text-gray-500"
                                @click="ligar(cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                    <path d="M4 5c0 8.284 6.716 15 15 15 1-1.5 1.5-3 1.5-4.5l-4-1.5-1.5 2A11.5 11.5 0 0 1 9 10.5l2-1.5-1.5-4C8 5 6.5 5.5 5 6.5 4.5 5.5 4 5.5 4 5Z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeAgendar"
                                type="button"
                                title="Agendar ligação"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-cyan hover:bg-cyan/10 hover:text-cyan"
                                @click="emit('agendar-ligacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                    <rect x="3" y="4.5" width="18" height="16" rx="1.5" />
                                    <path d="M3 9h18M8 3v3M16 3v3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="m9 15 2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeOrcamento"
                                type="button"
                                title="Criar orçamento"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-navy hover:bg-navy/10 hover:text-navy"
                                @click="criarOrcamento(cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                    <path d="M7 3h7l4 4v14H7Z" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="M14 3v4h4M9.5 13h5M9.5 16.5h5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                v-if="podeObservar"
                                type="button"
                                title="Observações"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-amber hover:bg-amber/10 hover:text-amber"
                                @click="emit('observacao', cliente)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
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
