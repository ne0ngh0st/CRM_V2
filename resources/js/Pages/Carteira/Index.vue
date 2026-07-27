<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import CarteiraTabela from '@/Components/Carteira/CarteiraTabela.vue';
import MotivoInatividadeModal from '@/Components/Carteira/MotivoInatividadeModal.vue';
import ObservacaoModal from '@/Components/Carteira/ObservacaoModal.vue';

const props = defineProps({
    role: String,
    clientes: Object,
    kpis: Object,
    filtros: Object,
    opcoes: Object,
    visao: Object,
});

const podeObservar = computed(() => ['vendedor', 'representante'].includes(props.role));

const filtros = reactive({
    busca: props.filtros.busca || '',
    estado: props.filtros.estado || '',
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    aderencia: props.filtros.aderencia || '',
    mostrar_ocultos: props.filtros.mostrar_ocultos || false,
    ordenar: props.filtros.ordenar || 'nome_asc',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function aplicarFiltros() {
    router.get(route('carteira.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['clientes', 'kpis', 'filtros', 'visao'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', estado: '', segmento: '', status: '', aderencia: '',
        mostrar_ocultos: false, ordenar: 'nome_asc', visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

const totalAtivos = computed(() => props.kpis.dentroSegmento.ativos + props.kpis.foraSegmento.ativos);
const totalInativando = computed(() => props.kpis.dentroSegmento.inativando + props.kpis.foraSegmento.inativando);
const totalInativos = computed(() => props.kpis.dentroSegmento.inativos + props.kpis.foraSegmento.inativos);

const modalMotivo = ref(false);
const modalObservacao = ref(false);
const clienteAtivo = ref(null);

function abrirMotivo(cliente) {
    clienteAtivo.value = cliente;
    modalMotivo.value = true;
}

function abrirObservacao(cliente) {
    clienteAtivo.value = cliente;
    modalObservacao.value = true;
}
</script>

<template>
    <Head title="Carteira" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Carteira">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="6" width="18" height="14" rx="1" />
                            <path d="M3 10h18M8 6V4h8v2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        {{ kpis.total }} cliente{{ kpis.total !== 1 ? 's' : '' }} · {{ kpis.pctDentro }}% no segmento
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.total" label="Total" />
                        <KpiTile :value="totalAtivos" label="Ativos" tone="ok" />
                        <KpiTile :value="totalInativando" label="Inativando" tone="warn" />
                        <KpiTile :value="totalInativos" label="Inativos" tone="danger" />
                        <KpiTile :value="`${kpis.pctDentro}%`" label="No segmento" tone="info" />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Razão social, CNPJ ou código..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Estado" :model-value="filtros.estado" @update:model-value="(v) => { filtros.estado = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="e in opcoes.estados" :key="e" :value="e">{{ e }}</option>
                        </FilterField>

                        <FilterField label="Segmento" :model-value="filtros.segmento" @update:model-value="(v) => { filtros.segmento = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="s in opcoes.segmentos" :key="s" :value="s">{{ s }}</option>
                        </FilterField>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="ativo">Ativo</option>
                            <option value="inativando">Inativando</option>
                            <option value="inativo">Inativo</option>
                        </FilterField>

                        <FilterField label="Aderência" :model-value="filtros.aderencia" @update:model-value="(v) => { filtros.aderencia = v; aplicarFiltros(); }">
                            <option value="">Todas</option>
                            <option value="dentro">No segmento</option>
                            <option value="fora">Fora do segmento</option>
                        </FilterField>

                        <FilterField v-if="visao.supervisores.length" label="Supervisão" :model-value="filtros.visao_supervisor" @update:model-value="(v) => { filtros.visao_supervisor = v; filtros.visao_vendedor = ''; aplicarFiltros(); }">
                            <option value="">Todas as Equipes</option>
                            <option v-for="s in visao.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField v-if="visao.mostrarSeletor" label="Vendedor" :model-value="filtros.visao_vendedor" @update:model-value="(v) => { filtros.visao_vendedor = v; aplicarFiltros(); }">
                            <option value="">Todos os Vendedores</option>
                            <option v-for="v in visao.vendedores" :key="v.cod_vendedor" :value="v.cod_vendedor">{{ v.nome }}</option>
                        </FilterField>

                        <FilterField label="Ordenar por" :model-value="filtros.ordenar" @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }">
                            <option value="nome_asc">Razão social · A-Z</option>
                            <option value="ultima_compra_desc">Última compra · mais recente</option>
                            <option value="ultima_compra_asc">Última compra · mais antiga</option>
                        </FilterField>

                        <label class="flex items-center gap-1.5 self-end pb-1.5 text-xs text-gray-600">
                            <input
                                type="checkbox"
                                :checked="filtros.mostrar_ocultos"
                                class="rounded border-gray-300 text-teal focus:ring-cyan"
                                @change="filtros.mostrar_ocultos = $event.target.checked; aplicarFiltros();"
                            />
                            Mostrar ocultos
                        </label>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <DarkCard title="Carteira de Clientes" :subtitle="`${kpis.total} cliente${kpis.total !== 1 ? 's' : ''} no escopo atual`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>

                    <CarteiraTabela v-if="clientes.data.length" :clientes="clientes.data" :pode-observar="podeObservar" @motivo-inatividade="abrirMotivo" @observacao="abrirObservacao" />
                    <p v-else class="text-sm text-gray-400">Nenhum cliente encontrado com os filtros atuais.</p>

                    <div class="mt-4">
                        <Pagination :meta="clientes" :only="['clientes']" />
                    </div>
                </DarkCard>
            </div>
        </div>

        <MotivoInatividadeModal :show="modalMotivo" :cliente="clienteAtivo" @close="modalMotivo = false" />
        <ObservacaoModal v-if="podeObservar" :show="modalObservacao" :cliente="clienteAtivo" @close="modalObservacao = false" />
    </AuthenticatedLayout>
</template>
