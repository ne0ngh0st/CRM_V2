<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import PedidosEmitidosTabela from '@/Components/Pedidos/PedidosEmitidosTabela.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';

const props = defineProps({
    role: String,
    pedidos: Object,
    kpis: Object,
    periodo: Object,
    filtros: Object,
    opcoes: Object,
    visao: Object,
});

const MESES = [
    { value: 1, label: 'Janeiro' },
    { value: 2, label: 'Fevereiro' },
    { value: 3, label: 'Março' },
    { value: 4, label: 'Abril' },
    { value: 5, label: 'Maio' },
    { value: 6, label: 'Junho' },
    { value: 7, label: 'Julho' },
    { value: 8, label: 'Agosto' },
    { value: 9, label: 'Setembro' },
    { value: 10, label: 'Outubro' },
    { value: 11, label: 'Novembro' },
    { value: 12, label: 'Dezembro' },
];

const filtros = reactive({
    ano: String(props.filtros.ano),
    mes: String(props.filtros.mes),
    busca: props.filtros.busca || '',
    faturamento: props.filtros.faturamento || '',
    ordenar: props.filtros.ordenar || 'data_pedido_desc',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function aplicarFiltros(extra = {}) {
    router.get(route('pedidos.emitidos'), { ...filtros, ...extra }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['pedidos', 'kpis', 'periodo', 'filtros', 'visao'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(() => aplicarFiltros(), 300);
}

function setFaturamento(valor) {
    filtros.faturamento = filtros.faturamento === valor ? '' : valor;
    aplicarFiltros();
}

function limparFiltros() {
    Object.assign(filtros, {
        ano: String(new Date().getFullYear()),
        mes: String(new Date().getMonth() + 1),
        busca: '',
        faturamento: '',
        ordenar: 'data_pedido_desc',
        visao_supervisor: '',
        visao_vendedor: '',
    });
    aplicarFiltros();
}

const temFiltrosAtivos = computed(() =>
    filtros.busca !== ''
    || filtros.faturamento !== ''
    || !!filtros.visao_supervisor
    || !!filtros.visao_vendedor,
);

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

const periodoLabel = computed(() => {
    const ini = props.periodo.inicio?.split('-').reverse().join('/');
    const fim = props.periodo.fim?.split('-').reverse().join('/');
    return `${ini} → ${fim}${props.periodo.d1 ? ' (D-1)' : ''}`;
});
</script>

<template>
    <Head title="Pedidos Emitidos" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Pedidos Emitidos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 7h16v12H4z" />
                            <path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2" stroke-linecap="round" />
                            <path d="M9 12h6M9 15h4" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Todos os pedidos emitidos no período {{ periodoLabel }} — mesma base da Home (meta de venda).
                    </template>
                    <template #meta>
                        <button type="button" class="min-w-[84px]" @click="setFaturamento('')">
                            <KpiTile :value="kpis.total" label="Emitidos" :tone="filtros.faturamento === '' ? 'info' : 'default'" />
                        </button>
                        <button type="button" class="min-w-[84px]" @click="setFaturamento('faturado')">
                            <KpiTile :value="kpis.faturados" label="Faturados" :tone="filtros.faturamento === 'faturado' ? 'ok' : 'default'" />
                        </button>
                        <button type="button" class="min-w-[84px]" @click="setFaturamento('aberto')">
                            <KpiTile :value="kpis.emAberto" label="Ainda abertos" :tone="filtros.faturamento === 'aberto' ? 'warn' : 'default'" />
                        </button>
                        <div>
                            <KpiTile :value="formatBRL(kpis.valorTotal)" label="Valor total" compact />
                        </div>
                    </template>
                    <template #filtros>
                        <FilterField label="Ano" :model-value="filtros.ano" @update:model-value="(v) => { filtros.ano = v; aplicarFiltros(); }">
                            <option v-for="a in opcoes.anos" :key="a" :value="String(a)">{{ a }}</option>
                        </FilterField>

                        <FilterField label="Mês" :model-value="filtros.mes" @update:model-value="(v) => { filtros.mes = v; aplicarFiltros(); }">
                            <option v-for="m in MESES" :key="m.value" :value="String(m.value)">{{ m.label }}</option>
                        </FilterField>

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

                        <FilterField
                            v-if="visao.supervisores.length"
                            label="Supervisão"
                            :model-value="filtros.visao_supervisor"
                            @update:model-value="(v) => { filtros.visao_supervisor = v; filtros.visao_vendedor = ''; aplicarFiltros(); }"
                        >
                            <option value="">Todas as Equipes</option>
                            <option v-for="s in visao.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField
                            v-if="visao.mostrarSeletor"
                            label="Vendedor"
                            :model-value="filtros.visao_vendedor"
                            @update:model-value="(v) => { filtros.visao_vendedor = v; aplicarFiltros(); }"
                        >
                            <option value="">Todos os Vendedores</option>
                            <option v-for="v in visao.vendedores" :key="v.cod_vendedor" :value="v.cod_vendedor">{{ v.nome }}</option>
                        </FilterField>

                        <FilterField label="Ordenar por" :model-value="filtros.ordenar" @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }">
                            <option value="data_pedido_desc">Data do pedido · mais recente</option>
                            <option value="data_pedido_asc">Data do pedido · mais antiga</option>
                            <option value="faturamento_desc">Faturamento · mais recente</option>
                            <option value="faturamento_asc">Faturamento · mais antigo</option>
                            <option value="valor_desc">Valor · maior primeiro</option>
                            <option value="valor_asc">Valor · menor primeiro</option>
                        </FilterField>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <DarkCard title="Pedidos Emitidos" :subtitle="`${pedidos.total} no período`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <ExportarExcelButton
                            rota="pedidos.exportarEmitidos"
                            :filtros="filtros"
                            :tem-filtros-ativos="temFiltrosAtivos"
                        />
                    </template>

                    <PedidosEmitidosTabela v-if="pedidos.data.length" :pedidos="pedidos.data" />
                    <p v-else class="text-sm text-gray-400">Nenhum pedido emitido encontrado com os filtros atuais.</p>

                    <div class="mt-4">
                        <Pagination :meta="pedidos" :only="['pedidos']" />
                    </div>
                </DarkCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
