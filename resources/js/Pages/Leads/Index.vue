<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import CalendarioAgendamentos from '@/Components/Carteira/CalendarioAgendamentos.vue';
import LeadsTabela from '@/Components/Leads/LeadsTabela.vue';
import AgendarLigacaoLeadModal from '@/Components/Leads/AgendarLigacaoLeadModal.vue';
import ObservacoesModal from '@/Components/Observacoes/ObservacoesModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import WordpressCapturaBar from '@/Components/Leads/WordpressCapturaBar.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';

const props = defineProps({
    role: String,
    aba: { type: String, default: 'leads' },
    leads: Object,
    kpis: Object,
    agendamentos: { type: Array, default: () => [] },
    filtros: Object,
    opcoes: Object,
    visao: Object,
    somenteWordpress: { type: Boolean, default: false },
    wordpressCaptura: { type: Object, default: null },
});

const podeAgirNoLead = computed(() =>
    ['vendedor', 'representante'].includes(props.role) || props.somenteWordpress,
);

const filtros = reactive({
    busca: props.filtros.busca || '',
    estado: props.filtros.estado || '',
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    origem: props.filtros.origem || '',
    ordenar: props.filtros.ordenar || 'nome_asc',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function paramsComAba(aba = props.aba) {
    return { ...filtros, aba };
}

function aplicarFiltros() {
    router.get(route('leads.index'), paramsComAba('leads'), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['leads', 'kpis', 'filtros', 'visao', 'aba'],
    });
}

// `agendamentos` e prop opcional no servidor: so vem quando pedida no `only`.
// Filtrar mexe na lista de leads, nao na agenda.
function trocarAba(aba) {
    router.get(route('leads.index'), paramsComAba(aba), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: aba === 'calendario'
            ? ['leads', 'kpis', 'filtros', 'visao', 'aba', 'agendamentos']
            : ['leads', 'kpis', 'filtros', 'visao', 'aba'],
    });
}

// Entrar direto por URL (/leads?aba=calendario) e visita completa, e visita completa
// nao traz prop opcional — sem isto o calendario abriria vazio.
onMounted(() => {
    if (props.aba === 'calendario' && !props.agendamentos.length) {
        router.reload({ only: ['agendamentos'], preserveState: true, preserveScroll: true });
    }
});

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', estado: '', segmento: '', status: '',
        origem: props.somenteWordpress ? 'wordpress' : '',
        ordenar: 'nome_asc', visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

const modalObservacao = ref(false);
const modalAgendamento = ref(false);
const modalCaptura = ref(false);
const leadAtivo = ref(null);
const capturaJson = ref(null);
const capturaErro = ref('');

function abrirObservacao(lead) {
    leadAtivo.value = lead;
    modalObservacao.value = true;
}

function abrirAgendamento(lead) {
    leadAtivo.value = lead;
    modalAgendamento.value = true;
}

async function abrirCaptura(lead) {
    leadAtivo.value = lead;
    capturaJson.value = null;
    capturaErro.value = '';
    modalCaptura.value = true;
    try {
        const res = await fetch(route('leads.captura', lead.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        capturaJson.value = await res.json();
    } catch {
        capturaErro.value = 'Não foi possível carregar o payload desta captura.';
    }
}

const temFiltrosAtivos = computed(() => {
    const campos = ['busca', 'estado', 'segmento', 'status'];
    if (!props.somenteWordpress) {
        campos.push('origem');
    }

    return campos.some((k) => filtros[k] !== '')
        || !!filtros.visao_supervisor
        || !!filtros.visao_vendedor;
});
</script>

<template>
    <Head title="Leads" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Leads">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <circle cx="9" cy="7" r="3" />
                            <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                            <path d="M19 8v6M16 11h6" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        <template v-if="somenteWordpress">
                            Leads capturados pelo site (WordPress). Sem transferência — a atribuição continua no TOTVS/import.
                        </template>
                        <template v-else>
                            Carteira de prospects — base do sistema, leads manuais e leads do site (WordPress). Sem transferência (TOTVS/import cuida da atribuição).
                        </template>
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.total" label="Total" />
                        <KpiTile v-if="!somenteWordpress" :value="kpis.sistema" label="Sistema" tone="info" :href="route('leads.index', { ...filtros, origem: 'sistema', aba: 'leads' })" />
                        <KpiTile v-if="!somenteWordpress" :value="kpis.manual" label="Manuais" tone="warn" :href="route('leads.index', { ...filtros, origem: 'manual', aba: 'leads' })" />
                        <KpiTile :value="kpis.wordpress ?? 0" label="WordPress" tone="ok" :href="route('leads.index', { ...filtros, origem: 'wordpress', aba: 'leads' })" />
                        <KpiTile :value="kpis.ativos" label="Ativos" tone="ok" />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Nome, CNPJ, e-mail ou telefone..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="UF" :model-value="filtros.estado" @update:model-value="(v) => { filtros.estado = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="e in opcoes.estados" :key="e" :value="e">{{ e }}</option>
                        </FilterField>

                        <FilterField label="Segmento" :model-value="filtros.segmento" @update:model-value="(v) => { filtros.segmento = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="s in opcoes.segmentos" :key="s" :value="s">{{ s }}</option>
                        </FilterField>

                        <FilterField v-if="!somenteWordpress" label="Origem" :model-value="filtros.origem" @update:model-value="(v) => { filtros.origem = v; aplicarFiltros(); }">
                            <option value="">Todas</option>
                            <option value="sistema">Sistema</option>
                            <option value="manual">Manual</option>
                            <option value="wordpress">WordPress</option>
                        </FilterField>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                            <option value="convertido">Convertido</option>
                        </FilterField>

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

                        <FilterField label="Ordenar" :model-value="filtros.ordenar" @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }">
                            <option value="nome_asc">Nome</option>
                            <option value="valor_desc">Valor estimado</option>
                            <option value="recentes">Mais recentes</option>
                        </FilterField>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <WordpressCapturaBar v-if="wordpressCaptura" :captura="wordpressCaptura" />

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide"
                        :class="aba === 'leads' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600'"
                        @click="trocarAba('leads')"
                    >
                        Leads
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide"
                        :class="aba === 'calendario' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600'"
                        @click="trocarAba('calendario')"
                    >
                        Calendário
                    </button>
                </div>

                <template v-if="aba === 'leads'">
                    <DarkCard title="Prospects" :subtitle="`${leads.total} lead${leads.total !== 1 ? 's' : ''}`">
                        <template #icon>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                            </svg>
                        </template>
                        <template #actions>
                            <ExportarExcelButton
                                rota="leads.exportar"
                                :filtros="filtros"
                                :tem-filtros-ativos="temFiltrosAtivos"
                            />
                        </template>

                        <LeadsTabela
                            v-if="leads.data.length"
                            :leads="leads.data"
                            :pode-ligar="podeAgirNoLead"
                            :pode-agendar="podeAgirNoLead"
                            :pode-orcamento="podeAgirNoLead"
                            :pode-observar="true"
                            :pode-excluir="true"
                            @observacao="abrirObservacao"
                            @agendar-ligacao="abrirAgendamento"
                            @captura="abrirCaptura"
                        />
                        <p v-else class="text-sm text-gray-400">Nenhum lead encontrado com os filtros atuais.</p>

                        <div class="mt-4">
                            <Pagination :meta="leads" :only="['leads']" />
                        </div>
                    </DarkCard>
                </template>

                <CalendarioAgendamentos
                    v-else
                    :agendamentos="agendamentos"
                    status-route="leads.agendamentoStatus"
                />
            </div>
        </div>

        <ObservacoesModal
            :show="modalObservacao"
            :subtitulo="leadAtivo?.razaoSocial || leadAtivo?.nome || ''"
            :historico-url="leadAtivo ? route('observacoes.porLead', leadAtivo.id) : null"
            :payload="leadAtivo ? { lead_id: leadAtivo.id, cnpj: leadAtivo.cnpj || undefined } : {}"
            @close="modalObservacao = false"
        />
        <AgendarLigacaoLeadModal
            :show="modalAgendamento"
            :lead="leadAtivo"
            @close="modalAgendamento = false"
        />
        <ModalPadrao
            :show="modalCaptura"
            titulo="Captura do site"
            :subtitulo="leadAtivo?.razaoSocial || leadAtivo?.nome || ''"
            max-width="2xl"
            @close="modalCaptura = false"
        >
            <p v-if="capturaErro" class="text-sm text-red-600">{{ capturaErro }}</p>
            <p v-else-if="!capturaJson" class="text-sm text-gray-400">Carregando…</p>
            <template v-else>
                <p class="mb-2 text-xs text-gray-500">
                    {{ capturaJson.fonte }} · {{ capturaJson.recebidoEm }}
                    <span v-if="capturaJson.formulario"> · {{ capturaJson.formulario }}</span>
                </p>
                <pre class="max-h-96 overflow-auto rounded border border-gray-200 bg-gray-50 p-3 text-[0.7rem] leading-4 text-gray-700">{{ JSON.stringify(capturaJson.payload, null, 2) }}</pre>
            </template>
        </ModalPadrao>
    </AuthenticatedLayout>
</template>
