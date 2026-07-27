<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira.js';

defineProps({
    cliente: Object,
    kpis: Object,
    pedidos: Object,
});

const ROTULOS_STATUS_PEDIDO = {
    separacao: 'Separação',
    bloqueio: 'Bloqueio',
    wms: 'WMS',
    liberado: 'Liberado',
    faturado: 'Faturado',
};

const TONS_STATUS_PEDIDO = {
    separacao: 'warn',
    bloqueio: 'danger',
    wms: 'warn',
    liberado: 'ok',
    faturado: 'ok',
};

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

const campos = [
    { label: 'Código / Loja', valor: (c) => `${c.codCliente} / ${c.loja}` },
    { label: 'CNPJ', valor: (c) => c.cnpj ?? '—' },
    { label: 'Nome fantasia', valor: (c) => c.nomeFantasia ?? '—' },
    { label: 'Estado', valor: (c) => c.estado ?? '—' },
    { label: 'CEP', valor: (c) => c.cep ?? '—' },
    { label: 'Telefone', valor: (c) => c.telefone ?? '—' },
    { label: 'E-mail', valor: (c) => c.email ?? '—' },
    { label: 'Segmento', valor: (c) => c.segmento ?? '—' },
    { label: 'Vendedor', valor: (c) => c.vendedorNome ?? '—' },
];
</script>

<template>
    <Head :title="cliente.razaoSocial" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <Link :href="route('carteira.index')" class="inline-flex w-fit items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                        <polyline points="15,6 9,12 15,18" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Voltar à carteira
                </Link>

                <PageHero :title="cliente.razaoSocial">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="6" width="18" height="14" rx="1" />
                            <path d="M3 10h18M8 6V4h8v2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        {{ cliente.cnpj ?? 'CNPJ não cadastrado' }} · {{ cliente.codCliente }}/{{ cliente.loja }}
                    </template>
                    <template #meta>
                        <StatusPill :tone="TONS_STATUS_CARTEIRA[cliente.status]">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
                        <KpiTile :value="kpis.pedidos" label="Pedidos" />
                        <KpiTile :value="formatBRL(kpis.volumeTotal)" label="Volume total" compact />
                        <KpiTile :value="formatBRL(kpis.ticketMedio)" label="Ticket médio" compact />
                        <KpiTile :value="kpis.ultimaCompra" label="Última compra" />
                    </template>
                </PageHero>

                <DarkCard title="Dados cadastrais" subtitle="Espelho de leitura do TOTVS — edição é feita lá, não aqui">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>

                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="campo in campos" :key="campo.label">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ campo.label }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800">{{ campo.valor(cliente) }}</dd>
                        </div>
                    </dl>
                </DarkCard>

                <DarkCard title="Histórico de pedidos" :subtitle="`${kpis.pedidos} pedido${kpis.pedidos !== 1 ? 's' : ''} · ${formatBRL(kpis.volumeTotal)}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M7 3h7l4 4v14H7Z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M14 3v4h4M9.5 13h5M9.5 16.5h5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>

                    <div v-if="pedidos.data.length" class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-sm">
                            <thead>
                                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                                    <th class="px-3 py-2.5 font-semibold">Pedido</th>
                                    <th class="px-3 py-2.5 font-semibold">Data</th>
                                    <th class="px-3 py-2.5 font-semibold">Faturamento</th>
                                    <th class="px-3 py-2.5 font-semibold">Status</th>
                                    <th class="px-3 py-2.5 font-semibold">Itens</th>
                                    <th class="px-3 py-2.5 font-semibold">Valor</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <tr
                                    v-for="pedido in pedidos.data"
                                    :key="pedido.id"
                                    class="divide-x divide-gray-200 hover:bg-gray-50/60"
                                >
                                    <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ pedido.numeroPedido }}</td>
                                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ pedido.dataPedido }}</td>
                                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ pedido.dataFaturamento ?? 'Em aberto' }}</td>
                                    <td class="px-3 py-2.5 text-center align-middle">
                                        <StatusPill :tone="TONS_STATUS_PEDIDO[pedido.status] || 'neutral'">
                                            {{ ROTULOS_STATUS_PEDIDO[pedido.status] || pedido.status }}
                                        </StatusPill>
                                    </td>
                                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ pedido.itensCount }}</td>
                                    <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ formatBRL(pedido.valorTotal) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-gray-400">Nenhum pedido encontrado pra este cliente.</p>

                    <div class="mt-4">
                        <Pagination :meta="pedidos" :only="['pedidos']" />
                    </div>
                </DarkCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
