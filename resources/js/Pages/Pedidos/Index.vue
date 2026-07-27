<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import PedidosTabela from '@/Components/Pedidos/PedidosTabela.vue';
import { ROTULOS_STATUS_PEDIDO } from '@/constants/pedidos.js';

const props = defineProps({
    role: String,
    pedidos: Object,
    kpis: Object,
    filtros: Object,
    opcoes: Object,
    visao: Object,
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    situacao: props.filtros.situacao || 'todos',
    status: props.filtros.status || '',
    data_inicio: props.filtros.data_inicio || '',
    data_fim: props.filtros.data_fim || '',
    ordenar: props.filtros.ordenar || 'previsao_asc',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function aplicarFiltros() {
    router.get(route('pedidos.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['pedidos', 'kpis', 'filtros', 'visao'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', situacao: 'todos', status: '', data_inicio: '', data_fim: '',
        ordenar: 'previsao_asc', visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <Head title="Pedidos em Aberto" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Pedidos em Aberto">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="7" width="18" height="14" rx="1" />
                            <path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Pedidos ainda não faturados — separação, bloqueio, WMS ou liberados.
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.totalAberto" label="Em aberto" />
                        <KpiTile :value="kpis.atrasados" label="Atrasados" tone="danger" />
                        <KpiTile :value="kpis.vencendo" label="Vencendo 7d" tone="warn" />
                        <KpiTile :value="formatBRL(kpis.valorEmRisco)" label="Valor em risco" compact />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Nº pedido, cliente, CNPJ ou produto..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Situação" :model-value="filtros.situacao" @update:model-value="(v) => { filtros.situacao = v; aplicarFiltros(); }">
                            <option value="todos">Todos</option>
                            <option value="atrasado">Atrasado</option>
                            <option value="vencendo">Vencendo em 7 dias</option>
                            <option value="no_prazo">No prazo</option>
                        </FilterField>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="s in opcoes.status" :key="s" :value="s">{{ ROTULOS_STATUS_PEDIDO[s] }}</option>
                        </FilterField>

                        <div class="flex min-w-[130px] flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Pedido de</label>
                            <input
                                v-model="filtros.data_inicio"
                                type="date"
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @change="aplicarFiltros"
                            />
                        </div>

                        <div class="flex min-w-[130px] flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Pedido até</label>
                            <input
                                v-model="filtros.data_fim"
                                type="date"
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @change="aplicarFiltros"
                            />
                        </div>

                        <FilterField v-if="visao.supervisores.length" label="Supervisão" :model-value="filtros.visao_supervisor" @update:model-value="(v) => { filtros.visao_supervisor = v; filtros.visao_vendedor = ''; aplicarFiltros(); }">
                            <option value="">Todas as Equipes</option>
                            <option v-for="s in visao.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField v-if="visao.mostrarSeletor" label="Vendedor" :model-value="filtros.visao_vendedor" @update:model-value="(v) => { filtros.visao_vendedor = v; aplicarFiltros(); }">
                            <option value="">Todos os Vendedores</option>
                            <option v-for="v in visao.vendedores" :key="v.cod_vendedor" :value="v.cod_vendedor">{{ v.nome }}</option>
                        </FilterField>

                        <FilterField label="Ordenar por" :model-value="filtros.ordenar" @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }">
                            <option value="previsao_asc">Previsão · mais urgente</option>
                            <option value="previsao_desc">Previsão · mais distante</option>
                            <option value="valor_desc">Valor · maior primeiro</option>
                            <option value="valor_asc">Valor · menor primeiro</option>
                            <option value="data_pedido_desc">Data do pedido · mais recente</option>
                            <option value="data_pedido_asc">Data do pedido · mais antiga</option>
                        </FilterField>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <DarkCard title="Pedidos em Aberto" :subtitle="`${kpis.totalAberto} pedido${kpis.totalAberto !== 1 ? 's' : ''} não faturado${kpis.totalAberto !== 1 ? 's' : ''}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>

                    <PedidosTabela v-if="pedidos.data.length" :pedidos="pedidos.data" />
                    <p v-else class="text-sm text-gray-400">Nenhum pedido em aberto encontrado com os filtros atuais.</p>

                    <div class="mt-4">
                        <Pagination :meta="pedidos" :only="['pedidos']" />
                    </div>
                </DarkCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
