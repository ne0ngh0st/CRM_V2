<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import OrcamentosTabela from '@/Components/Orcamentos/OrcamentosTabela.vue';
import RejeitarOrcamentoModal from '@/Components/Orcamentos/RejeitarOrcamentoModal.vue';
import ExcluirOrcamentoModal from '@/Components/Orcamentos/ExcluirOrcamentoModal.vue';
import { ROTULOS_STATUS_ORCAMENTO, ROTULOS_NIVEL_APROVACAO } from '@/constants/orcamentos.js';

const props = defineProps({
    role: String,
    podeExcluir: Boolean,
    orcamentos: Object,
    kpis: Object,
    filtros: Object,
    visao: Object,
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    status: props.filtros.status || '',
    nivel: props.filtros.nivel || '',
    data_inicio: props.filtros.data_inicio || '',
    data_fim: props.filtros.data_fim || '',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function aplicarFiltros() {
    router.get(route('orcamentos.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['orcamentos', 'kpis', 'filtros', 'visao'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', status: '', nivel: '', data_inicio: '', data_fim: '',
        visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

const modalRejeitar = ref(false);
const modalExcluir = ref(false);
const orcamentoAtivo = ref(null);

function abrirRejeitar(orcamento) {
    orcamentoAtivo.value = orcamento;
    modalRejeitar.value = true;
}

function abrirExcluir(orcamento) {
    orcamentoAtivo.value = orcamento;
    modalExcluir.value = true;
}

function aprovar(orcamento) {
    router.patch(route('orcamentos.aprovar', orcamento.id), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Orçamentos" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Orçamentos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="6" y="3" width="12" height="18" rx="1" />
                            <line x1="9" y1="8" x2="15" y2="8" stroke-linecap="round" />
                            <line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" />
                            <line x1="9" y1="16" x2="13" y2="16" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Aprovação interna por nível de desconto — supervisor ou diretor conforme a regra de negócio.
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.total" label="Total" />
                        <KpiTile :value="kpis.aguardandoSupervisor" label="Aguard. supervisor" tone="warn" />
                        <KpiTile :value="kpis.aguardandoDiretor" label="Aguard. diretor" tone="warn" />
                        <KpiTile :value="kpis.aprovados" label="Aprovados" tone="ok" />
                        <KpiTile :value="kpis.rejeitados" label="Rejeitados" tone="danger" />
                        <KpiTile :value="formatBRL(kpis.valorAprovado)" label="Valor aprovado" compact />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Cliente ou CNPJ..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="(rotulo, valor) in ROTULOS_STATUS_ORCAMENTO" :key="valor" :value="valor">{{ rotulo }}</option>
                        </FilterField>

                        <FilterField label="Nível" :model-value="filtros.nivel" @update:model-value="(v) => { filtros.nivel = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="(rotulo, valor) in ROTULOS_NIVEL_APROVACAO" :key="valor" :value="valor">{{ rotulo }}</option>
                        </FilterField>

                        <div class="flex min-w-[130px] flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Criado de</label>
                            <input
                                v-model="filtros.data_inicio"
                                type="date"
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @change="aplicarFiltros"
                            />
                        </div>

                        <div class="flex min-w-[130px] flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Criado até</label>
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

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <DarkCard title="Orçamentos" :subtitle="`${kpis.total} orçamento${kpis.total !== 1 ? 's' : ''}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <Link
                            v-if="role === 'admin'"
                            :href="route('etiquetas.materiaPrima.index')"
                            class="rounded border border-gray-300 px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100"
                        >
                            Matéria-prima de etiqueta
                        </Link>
                        <Link :href="route('orcamentos.novo')" class="rounded bg-teal px-3 py-1 text-xs font-medium text-white hover:bg-teal/90">
                            + Novo Orçamento
                        </Link>
                    </template>

                    <OrcamentosTabela
                        v-if="orcamentos.data.length"
                        :orcamentos="orcamentos.data"
                        :pode-excluir="podeExcluir"
                        @editar="(o) => router.visit(route('orcamentos.editar', o.id))"
                        @copiar="(o) => router.visit(route('orcamentos.novo', { copiar_de: o.id }))"
                        @aprovar="aprovar"
                        @rejeitar="abrirRejeitar"
                        @excluir="abrirExcluir"
                    />
                    <p v-else class="text-sm text-gray-400">Nenhum orçamento encontrado com os filtros atuais.</p>

                    <div class="mt-4">
                        <Pagination :meta="orcamentos" :only="['orcamentos']" />
                    </div>
                </DarkCard>
            </div>
        </div>

        <RejeitarOrcamentoModal :show="modalRejeitar" :orcamento="orcamentoAtivo" @close="modalRejeitar = false" />
        <ExcluirOrcamentoModal :show="modalExcluir" :orcamento="orcamentoAtivo" @close="modalExcluir = false" />
    </AuthenticatedLayout>
</template>
