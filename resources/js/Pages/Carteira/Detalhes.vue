<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira.js';
// Esta página mantinha uma cópia local destes dois mapas, e a cópia não incluía
// `pendente_totvs` — todo pedido em aberto mostrava a string crua na coluna Status.
// (Regra de ouro nº 8: rótulo é decisão que mora num lugar só.)
import { ROTULOS_STATUS_PEDIDO, TONS_STATUS_PEDIDO } from '@/constants/pedidos.js';

defineProps({
    cliente: Object,
    kpis: Object,
    pedidos: Object,
});

const expandido = ref(null);

function toggle(id) {
    expandido.value = expandido.value === id ? null : id;
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function formatQuantidade(valor) {
    return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(valor ?? 0);
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
                        <StatusPill :tone="TONS_STATUS_CARTEIRA[cliente.status]" surface="dark">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
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

                    <div v-if="pedidos.data.length" class="tbl-wrap">
                        <table class="tbl min-w-[700px]">
                            <thead>
                                <tr class="tbl-head-row">
                                    <th class="tbl-th w-8"></th>
                                    <th class="tbl-th">Pedido</th>
                                    <th class="tbl-th">Data</th>
                                    <th class="tbl-th">Faturamento</th>
                                    <th class="tbl-th">Status</th>
                                    <th class="tbl-th">Itens</th>
                                    <th class="tbl-th">Valor</th>
                                </tr>
                            </thead>
                            <tbody class="tbl-body">
                                <template v-for="pedido in pedidos.data" :key="pedido.id">
                                    <tr class="tbl-row cursor-pointer" @click="toggle(pedido.id)">
                                        <td class="tbl-td text-gray-400">
                                            <svg
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                class="mx-auto h-3.5 w-3.5 transition-transform"
                                                :class="expandido === pedido.id ? 'rotate-90' : ''"
                                            >
                                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </td>
                                        <td class="tbl-td font-medium text-gray-800">{{ pedido.numeroPedido }}</td>
                                        <td class="tbl-td">{{ pedido.dataPedido }}</td>
                                        <td class="tbl-td">{{ pedido.dataFaturamento ?? 'Em aberto' }}</td>
                                        <td class="tbl-td">
                                            <StatusPill :tone="TONS_STATUS_PEDIDO[pedido.status] || 'neutral'" size="sm">
                                                {{ ROTULOS_STATUS_PEDIDO[pedido.status] || pedido.status }}
                                            </StatusPill>
                                        </td>
                                        <td class="tbl-td">{{ pedido.itensCount }}</td>
                                        <td class="tbl-td font-medium text-gray-800">{{ formatBRL(pedido.valorTotal) }}</td>
                                    </tr>
                                    <tr v-if="expandido === pedido.id" class="bg-gray-50">
                                        <td colspan="7" class="p-4">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do pedido</p>
                                            <table v-if="pedido.itens.length" class="tbl-itens">
                                                <thead>
                                                    <tr class="tbl-itens-head-row">
                                                        <th class="tbl-itens-th">Produto</th>
                                                        <th class="tbl-itens-th">Qtd.</th>
                                                        <th class="tbl-itens-th">Qtd. Liberada</th>
                                                        <th class="tbl-itens-th">Vlr. Unit.</th>
                                                        <th class="tbl-itens-th">Vlr. Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="tbl-body">
                                                    <tr v-for="item in pedido.itens" :key="item.id" class="tbl-itens-row">
                                                        <td class="tbl-itens-td">
                                                            <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                        </td>
                                                        <td class="tbl-itens-td">{{ formatQuantidade(item.quantidade) }}</td>
                                                        <td class="tbl-itens-td">
                                                            {{ item.quantidadeLiberada !== null ? formatQuantidade(item.quantidadeLiberada) : '—' }}
                                                        </td>
                                                        <td class="tbl-itens-td">{{ formatBRL(item.valorUnitario) }}</td>
                                                        <td class="tbl-itens-td font-medium text-gray-800">{{ formatBRL(item.valorTotal) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <p v-else class="mt-2 text-xs text-gray-400">Este pedido não tem itens registrados.</p>
                                        </td>
                                    </tr>
                                </template>
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
