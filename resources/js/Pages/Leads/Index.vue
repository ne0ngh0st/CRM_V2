<script setup>
import FunilQuadro from '@/Components/Leads/FunilQuadro.vue';
import { PERFIS_CARTEIRA } from '@/constants/perfis.js';
import CapturaWordpressDetalhe from '@/Components/Leads/CapturaWordpressDetalhe.vue';
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
import CartaoCnpjModal from '@/Components/Receita/CartaoCnpjModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import WordpressCapturaBar from '@/Components/Leads/WordpressCapturaBar.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import { contarFiltrosAtivos } from '@/utils/filtros';
import { ETAPAS_LEAD, ROTULOS_ETAPA_LEAD } from '@/constants/leads.js';

const props = defineProps({
    role: String,
    aba: { type: String, default: 'leads' },
    funil: { type: Object, default: null },
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
    PERFIS_CARTEIRA.includes(props.role) || props.somenteWordpress,
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
        // Cada aba pede só a prop opcional que vai usar — pedir as duas faria a aba
        // Leads pagar por duas consultas que iriam pro lixo.
        only: {
            calendario: ['leads', 'kpis', 'filtros', 'visao', 'aba', 'agendamentos'],
            funil: ['leads', 'kpis', 'filtros', 'visao', 'aba', 'funil'],
        }[aba] ?? ['leads', 'kpis', 'filtros', 'visao', 'aba'],
    });
}

// Entrar direto por URL (/leads?aba=calendario) e visita completa, e visita completa
// nao traz prop opcional — sem isto o calendario abriria vazio.
onMounted(() => {
    if (props.aba === 'funil' && !props.funil) {
        // Mesmo motivo do calendário: /leads?aba=funil e F5 são visita completa, e visita
        // completa não traz prop opcional — sem isto o quadro abriria vazio.
        router.reload({ only: ['funil'] });
    }

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

const modalCartaoCnpj = ref(false);

function abrirCartaoCnpj(lead) {
    leadAtivo.value = lead;
    modalCartaoCnpj.value = true;
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
        capturaErro.value = 'Não foi possível carregar os dados desta captura.';
    }
}

/*
 * Uma lista de campos, dois consumidores: a contagem no botão "Filtros" do celular e o
 * aviso do Excel. `origem` não conta na aba WordPress porque lá ela é fixa, não escolhida.
 *
 * ⚠️ O badge conta só o que o botão ESCONDE — a busca fica visível ao lado dele. O aviso
 * do Excel responde outra pergunta ("o arquivo sai recortado?") e aí a busca conta.
 */
const filtrosAtivos = computed(() => {
    const campos = ['estado', 'segmento', 'status', 'visao_supervisor', 'visao_vendedor'];

    if (! props.somenteWordpress) campos.push('origem');

    return contarFiltrosAtivos(filtros, campos);
});

const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '');
</script>

<template>
    <Head title="Leads" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Leads" :filtros-ativos="filtrosAtivos">
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
                    <!-- Busca e ordenação não colapsam no celular: abaixo de 640px a tabela
                         vira cartão e o `<thead>` não está lá, então este select é o único
                         acesso à ordenação — atrás do botão "Filtros" ele ficaria escondido. -->
                    <template #filtrosFixos>
                        <div class="flex w-full flex-col gap-1 sm:min-w-[200px] sm:max-w-[280px] sm:flex-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Nome, CNPJ, e-mail ou telefone..."
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Ordenar" :model-value="filtros.ordenar" @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }">
                            <option value="nome_asc">Nome</option>
                            <option value="valor_desc">Valor estimado</option>
                            <option value="recentes">Mais recentes</option>
                        </FilterField>
                    </template>

                    <template #filtros>
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

                        <!--
                            ⚠️ A chave da query continua `status` embora o que ela filtre
                            seja `etapa`: renomear quebraria link salvo e o `only:` da
                            recarga parcial. O rótulo é que estava mentindo.
                        -->
                        <FilterField label="Etapa" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todas</option>
                            <option v-for="etapa in ETAPAS_LEAD" :key="etapa" :value="etapa">
                                {{ ROTULOS_ETAPA_LEAD[etapa] }}
                            </option>
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

                        <button type="button" class="min-h-11 rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 sm:min-h-0 sm:self-end" @click="limparFiltros">
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
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide"
                        :class="aba === 'funil' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600'"
                        @click="trocarAba('funil')"
                    >
                        Funil
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
                            @cartao-cnpj="abrirCartaoCnpj"
                            @captura="abrirCaptura"
                        />
                        <p v-else class="text-sm text-gray-400">Nenhum lead encontrado com os filtros atuais.</p>

                        <div class="mt-4">
                            <Pagination :meta="leads" :only="['leads']" />
                        </div>
                    </DarkCard>
                </template>

                <DarkCard v-else-if="aba === 'funil'" title="Funil" subtitle="Onde cada negociação está — mais parado primeiro">
                    <template #icon>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4 w-4">
                            <path d="M3 4h14l-5 6v6l-4 2v-8L3 4z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <FunilQuadro v-if="funil" :funil="funil" />
                    <p v-else class="py-6 text-center text-sm text-gray-400">Carregando o funil…</p>
                </DarkCard>

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
        <CartaoCnpjModal
            :show="modalCartaoCnpj"
            :cliente="leadAtivo ? { razaoSocial: leadAtivo.razaoSocial || leadAtivo.nome, cnpj: leadAtivo.cnpj } : null"
            :url="leadAtivo ? route('leads.cartaoCnpj', leadAtivo.id) : ''"
            @close="modalCartaoCnpj = false"
        />
        <AgendarLigacaoLeadModal
            :show="modalAgendamento"
            :lead="leadAtivo"
            @close="modalAgendamento = false"
        />
        <ModalPadrao
            :show="modalCaptura"
            titulo="Dados recebidos do site"
            :subtitulo="leadAtivo?.razaoSocial || leadAtivo?.nome || ''"
            max-width="2xl"
            @close="modalCaptura = false"
        >
            <p v-if="capturaErro" class="text-sm text-red-600">{{ capturaErro }}</p>
            <p v-else-if="!capturaJson" class="text-sm text-gray-400">Carregando…</p>
            <CapturaWordpressDetalhe v-else :captura="capturaJson" />
        </ModalPadrao>
    </AuthenticatedLayout>
</template>
