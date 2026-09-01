<script setup>
import { ref, computed } from 'vue';
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
import { ROTULOS_STATUS_PEDIDO, TONS_STATUS_PEDIDO, ROTULOS_TIPO_FATURAMENTO } from '@/constants/pedidos.js';

const props = defineProps({
    cliente: Object,
    kpis: Object,
    pedidos: Object,
});

const expandido = ref(null);

function toggle(id) {
    expandido.value = expandido.value === id ? null : id;
}

/*
 * Os campos vindos do RLT 232 (RPS, tipo de faturamento, nota fiscal, peso, e os
 * logísticos) ficam vazios até o relatório ser ajustado no TOTVS.
 *
 * ⚠️ Por isso cada um só aparece quando ALGUM pedido/item da página o tem preenchido.
 * Sem isso a tela ganharia colunas em branco para todo mundo — que é exatamente o
 * defeito que já existe aqui: hoje 100% dos pedidos faturados têm data de entrega,
 * PCP, carga e condição de pagamento vazias, porque o relatório não as fornece.
 * Quando o dado começar a chegar, as colunas aparecem sozinhas, sem tocar no código.
 */
function algumPedidoTem(campo) {
    return props.pedidos.data.some((p) => p[campo] !== null && p[campo] !== '');
}

function algumItemTem(campo) {
    return props.pedidos.data.some((p) => p.itens.some((i) => i[campo] !== null && i[campo] !== ''));
}

const mostra = computed(() => ({
    rps: algumPedidoTem('rps'),
    tipoFaturamento: algumPedidoTem('tipoFaturamento'),
    condicaoPagamento: algumPedidoTem('condicaoPagamento'),
    entrega: algumPedidoTem('dataEntregaPrevista'),
    pcp: algumPedidoTem('dataPcp'),
    carga: algumPedidoTem('carga'),
    peso: algumPedidoTem('pesoTotal'),
    notaFiscal: algumItemTem('notaFiscal'),
    pesoItem: algumItemTem('pesoLinha'),
}));

const temDadosDoPedido = computed(() =>
    mostra.value.rps || mostra.value.tipoFaturamento || mostra.value.condicaoPagamento
    || mostra.value.entrega || mostra.value.pcp || mostra.value.carga || mostra.value.peso,
);

function formatPeso(kg) {
    if (kg === null || kg === undefined) {
        return '—';
    }

    return `${new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }).format(kg)} kg`;
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
                                            <!--
                                                Bloco inteiro condicionado a `temDadosDoPedido`: enquanto o RLT 232
                                                não fornecer estes campos, ele simplesmente não existe na tela.
                                            -->
                                            <div v-if="temDadosDoPedido" class="mb-4">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Dados do pedido</p>
                                                <dl class="mt-2 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                    <div v-if="mostra.tipoFaturamento && pedido.tipoFaturamento">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Natureza</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ ROTULOS_TIPO_FATURAMENTO[pedido.tipoFaturamento] || pedido.tipoFaturamento }}</dd>
                                                    </div>
                                                    <div v-if="mostra.rps && pedido.rps">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">RPS</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ pedido.rps }}</dd>
                                                    </div>
                                                    <div v-if="mostra.condicaoPagamento && pedido.condicaoPagamento">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Condição de pagamento</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ pedido.condicaoPagamento }}</dd>
                                                    </div>
                                                    <div v-if="mostra.peso && pedido.pesoTotal !== null">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Peso líquido</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ formatPeso(pedido.pesoTotal) }}</dd>
                                                    </div>
                                                    <div v-if="mostra.entrega && pedido.dataEntregaPrevista">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Entrega prevista</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ pedido.dataEntregaPrevista }}</dd>
                                                    </div>
                                                    <div v-if="mostra.pcp && pedido.dataPcp">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">PCP</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ pedido.dataPcp }}</dd>
                                                    </div>
                                                    <div v-if="mostra.carga && pedido.carga">
                                                        <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">Carga</dt>
                                                        <dd class="mt-0.5 text-sm text-gray-800">{{ pedido.carga }}</dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do pedido</p>
                                            <table v-if="pedido.itens.length" class="tbl-itens">
                                                <thead>
                                                    <tr class="tbl-itens-head-row">
                                                        <th class="tbl-itens-th">Produto</th>
                                                        <th v-if="mostra.notaFiscal" class="tbl-itens-th">Nota fiscal</th>
                                                        <th class="tbl-itens-th">Qtd.</th>
                                                        <th class="tbl-itens-th">Qtd. Liberada</th>
                                                        <th v-if="mostra.pesoItem" class="tbl-itens-th">Peso</th>
                                                        <th class="tbl-itens-th">Vlr. Unit.</th>
                                                        <th class="tbl-itens-th">Vlr. Total</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="tbl-body">
                                                    <tr v-for="item in pedido.itens" :key="item.id" class="tbl-itens-row">
                                                        <td class="tbl-itens-td">
                                                            <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                        </td>
                                                        <td v-if="mostra.notaFiscal" class="tbl-itens-td">{{ item.notaFiscal ?? '—' }}</td>
                                                        <td class="tbl-itens-td">{{ formatQuantidade(item.quantidade) }}</td>
                                                        <td class="tbl-itens-td">
                                                            {{ item.quantidadeLiberada !== null ? formatQuantidade(item.quantidadeLiberada) : '—' }}
                                                        </td>
                                                        <!-- Peso da LINHA (unitário × quantidade) — ver a migration 120000. -->
                                                        <td v-if="mostra.pesoItem" class="tbl-itens-td">{{ formatPeso(item.pesoLinha) }}</td>
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
