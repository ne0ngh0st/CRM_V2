<script setup>
import { ref } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_PEDIDO, TONS_STATUS_PEDIDO } from '@/constants/pedidos.js';

defineProps({
    pedidos: { type: Array, required: true },
});

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
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl min-w-[900px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th w-8"></th>
                    <th class="tbl-th">Pedido</th>
                    <th class="tbl-th">Cliente</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">Data Pedido</th>
                    <th class="tbl-th">Faturamento</th>
                    <th class="tbl-th">Valor Total</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Itens</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="pedido in pedidos" :key="pedido.id">
                    <tr
                        class="tbl-row cursor-pointer"
                        @click="toggle(pedido.id)"
                    >
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
                        <td class="tbl-td">
                            <span class="tbl-trunc max-w-[220px]" :title="pedido.cliente?.razaoSocial">{{ pedido.cliente?.razaoSocial ?? '—' }}</span>
                        </td>
                        <td class="tbl-td">{{ pedido.vendedorNome }}</td>
                        <td class="tbl-td">{{ pedido.dataPedido }}</td>
                        <td class="tbl-td">
                            <StatusPill :tone="pedido.faturado ? 'ok' : 'warn'" size="sm">
                                {{ pedido.faturado ? (pedido.dataFaturamento ?? 'Faturado') : 'Em aberto' }}
                            </StatusPill>
                        </td>
                        <td class="tbl-td font-medium text-gray-800">{{ formatBRL(pedido.valorTotal) }}</td>
                        <td class="tbl-td">
                            <StatusPill :tone="TONS_STATUS_PEDIDO[pedido.status] || 'neutral'" size="sm">
                                {{ ROTULOS_STATUS_PEDIDO[pedido.status] || pedido.status }}
                            </StatusPill>
                        </td>
                        <td class="tbl-td">{{ pedido.itens.length }}</td>
                    </tr>
                    <tr v-if="expandido === pedido.id" class="bg-gray-50">
                        <td colspan="9" class="p-4">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="lg:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do pedido</p>
                                    <table class="tbl-itens">
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
                                            <tr v-for="(item, idx) in pedido.itens" :key="idx" class="tbl-itens-row">
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
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Detalhes</p>
                                    <dl class="mt-2 space-y-1.5 text-xs text-gray-600">
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">CNPJ</dt>
                                            <dd class="text-right">{{ pedido.cliente?.cnpj ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Telefone</dt>
                                            <dd class="text-right">{{ pedido.cliente?.telefone ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Previsão fat.</dt>
                                            <dd class="text-right">{{ pedido.dataPrevisaoFaturamento ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Entrega prevista</dt>
                                            <dd class="text-right">{{ pedido.dataEntregaPrevista ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Data PCP</dt>
                                            <dd class="text-right">{{ pedido.dataPcp ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Carga</dt>
                                            <dd class="text-right">{{ pedido.carga ?? '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between gap-2">
                                            <dt class="text-gray-400">Condição pagto.</dt>
                                            <dd class="text-right">{{ pedido.condicaoPagamento ?? '—' }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
