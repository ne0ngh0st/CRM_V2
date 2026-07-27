<script setup>
import { ref } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_PEDIDO, TONS_STATUS_PEDIDO, ROTULOS_SITUACAO_PEDIDO, TONS_SITUACAO_PEDIDO } from '@/constants/pedidos.js';

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
    <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="w-8 px-2 py-2.5"></th>
                    <th class="px-3 py-2.5 font-semibold">Pedido</th>
                    <th class="px-3 py-2.5 font-semibold">Cliente</th>
                    <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                    <th class="px-3 py-2.5 font-semibold">Data Pedido</th>
                    <th class="px-3 py-2.5 font-semibold">Previsão Faturamento</th>
                    <th class="px-3 py-2.5 font-semibold">Valor Total</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Itens</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <template v-for="pedido in pedidos" :key="pedido.id">
                    <tr
                        class="cursor-pointer divide-x divide-gray-200 hover:bg-gray-50/60"
                        @click="toggle(pedido.id)"
                    >
                        <td class="px-2 py-2.5 text-center align-middle text-gray-400">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="mx-auto h-3.5 w-3.5 transition-transform"
                                :class="expandido === pedido.id ? 'rotate-90' : ''"
                            >
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ pedido.numeroPedido }}</td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-700">
                            <span class="mx-auto block max-w-[220px] truncate" :title="pedido.cliente?.razaoSocial">{{ pedido.cliente?.razaoSocial ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ pedido.vendedorNome }}</td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-500">{{ pedido.dataPedido }}</td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="TONS_SITUACAO_PEDIDO[pedido.situacao]">
                                {{ pedido.dataPrevisaoFaturamento ?? 'Sem previsão' }}
                                <template v-if="pedido.diasAtraso"> · {{ pedido.diasAtraso }}d</template>
                            </StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ formatBRL(pedido.valorTotal) }}</td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="TONS_STATUS_PEDIDO[pedido.status]">{{ ROTULOS_STATUS_PEDIDO[pedido.status] }}</StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-500">{{ pedido.itens.length }}</td>
                    </tr>
                    <tr v-if="expandido === pedido.id" class="bg-gray-50">
                        <td colspan="9" class="p-4">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="lg:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do pedido</p>
                                    <table class="mt-2 w-full overflow-hidden rounded border border-gray-200 text-xs">
                                        <thead>
                                            <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-100 text-center text-gray-500">
                                                <th class="px-2 py-1.5 font-semibold">Produto</th>
                                                <th class="px-2 py-1.5 font-semibold">Qtd.</th>
                                                <th class="px-2 py-1.5 font-semibold">Qtd. Liberada</th>
                                                <th class="px-2 py-1.5 font-semibold">Vlr. Unit.</th>
                                                <th class="px-2 py-1.5 font-semibold">Vlr. Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <tr v-for="(item, idx) in pedido.itens" :key="idx" class="divide-x divide-gray-200">
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-700">
                                                    <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                </td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">{{ formatQuantidade(item.quantidade) }}</td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">
                                                    {{ item.quantidadeLiberada !== null ? formatQuantidade(item.quantidadeLiberada) : '—' }}
                                                </td>
                                                <td class="px-2 py-1.5 text-center align-middle text-gray-600">{{ formatBRL(item.valorUnitario) }}</td>
                                                <td class="px-2 py-1.5 text-center align-middle font-medium text-gray-800">{{ formatBRL(item.valorTotal) }}</td>
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
                                            <dt class="text-gray-400">E-mail</dt>
                                            <dd class="truncate text-right" :title="pedido.cliente?.email">{{ pedido.cliente?.email ?? '—' }}</dd>
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
