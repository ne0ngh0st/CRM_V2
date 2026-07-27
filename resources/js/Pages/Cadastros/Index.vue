<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import MailtoPanel from '@/Components/Cadastros/MailtoPanel.vue';
import CadastroBobinaForm from '@/Components/Cadastros/CadastroBobinaForm.vue';
import CadastroBobinaTabela from '@/Components/Cadastros/CadastroBobinaTabela.vue';
import CadastroEtiquetaForm from '@/Components/Cadastros/CadastroEtiquetaForm.vue';
import CadastroEtiquetaTabela from '@/Components/Cadastros/CadastroEtiquetaTabela.vue';
import CadastroClienteForm from '@/Components/Cadastros/CadastroClienteForm.vue';
import CadastroClienteTabela from '@/Components/Cadastros/CadastroClienteTabela.vue';
import CadastroLeadForm from '@/Components/Cadastros/CadastroLeadForm.vue';
import CadastroLeadTabela from '@/Components/Cadastros/CadastroLeadTabela.vue';
import CadastroDetalhesModal from '@/Components/Cadastros/CadastroDetalhesModal.vue';

const props = defineProps({
    role: String,
    aba: { type: String, default: 'clientes' },
    subAbaClientes: { type: String, default: 'cliente' },
    bobinas: Object,
    etiquetas: Object,
    clientesFila: Object,
    leads: Object,
    filtros: Object,
    opcoes: Object,
    meta: Object,
    flashMailto: { type: Object, default: null },
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    status: props.filtros.status || '',
});

const mailtoLocal = ref(props.flashMailto);
watch(() => props.flashMailto, (v) => { mailtoLocal.value = v; });

const prefillBobina = ref(null);
const prefillEtiqueta = ref(null);
const detalhes = reactive({ show: false, titulo: '', campos: [] });

const abas = [
    { id: 'clientes', label: 'Clientes / Leads' },
    { id: 'bobinas', label: 'Bobinas' },
    { id: 'etiquetas', label: 'Etiquetas' },
];

const statusOpcoes = computed(() => {
    if (props.aba === 'clientes' && props.subAbaClientes === 'cliente') {
        return [
            { value: 'pendente', label: 'Pendente' },
            { value: 'em_analise', label: 'Em análise' },
            { value: 'processado', label: 'Processado' },
            { value: 'rejeitado', label: 'Rejeitado' },
        ];
    }
    if (props.aba === 'clientes' && props.subAbaClientes === 'lead') {
        return [{ value: 'ativo', label: 'Ativo' }];
    }
    return [
        { value: 'pendente', label: 'Pendente' },
        { value: 'enviado', label: 'Enviado' },
    ];
});

function paramsBase(extra = {}) {
    return {
        aba: props.aba,
        sub: props.subAbaClientes,
        busca: filtros.busca,
        status: filtros.status,
        ...extra,
    };
}

function navegar(extra = {}, only = null) {
    router.get(route('cadastros.index'), paramsBase(extra), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: only || ['bobinas', 'etiquetas', 'clientesFila', 'leads', 'filtros', 'aba', 'subAbaClientes', 'flashMailto'],
    });
}

function trocarAba(aba) {
    filtros.status = '';
    navegar({ aba, sub: aba === 'clientes' ? props.subAbaClientes : undefined });
}

function trocarSub(sub) {
    filtros.status = '';
    navegar({ aba: 'clientes', sub });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(() => navegar(), 300);
}

function limparFiltros() {
    filtros.busca = '';
    filtros.status = '';
    navegar();
}

function abrirDetalhes(titulo, item, mapa) {
    detalhes.titulo = titulo;
    detalhes.campos = mapa.map(([label, key]) => ({ label, value: item[key] }));
    detalhes.show = true;
}

function excluir(routeName, id) {
    if (!confirm('Excluir este registro?')) return;
    router.delete(route(routeName, id), { preserveScroll: true });
}

function enviarBobina(item) {
    router.post(route('cadastros.bobinas.enviar', item.id), {}, { preserveScroll: true });
}

function enviarEtiqueta(item) {
    router.post(route('cadastros.etiquetas.enviar', item.id), {}, { preserveScroll: true });
}

const tabBtn = (ativo) =>
    ativo
        ? 'border-cyan bg-white text-gray-900'
        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700';
</script>

<template>
    <Head title="Cadastros" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Cadastros">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M9 12h6M9 16h6M7 4h7l5 5v11a1 1 0 01-1 1H7a1 1 0 01-1-1V5a1 1 0 011-1z" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Cliente, lead, bobina e etiqueta — tudo numa página. Solicitações 1:1 com o legado.
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Buscar nas solicitações..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>
                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; navegar(); }">
                            <option value="">Todos</option>
                            <option v-for="s in statusOpcoes" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </FilterField>
                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <MailtoPanel v-if="mailtoLocal" :mailto="mailtoLocal" @dismiss="mailtoLocal = null" />

                <div class="flex flex-wrap gap-1 border-b border-gray-300">
                    <button
                        v-for="tab in abas"
                        :key="tab.id"
                        type="button"
                        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition"
                        :class="tabBtn(aba === tab.id)"
                        @click="trocarAba(tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <!-- CLIENTES -->
                <template v-if="aba === 'clientes'">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-xs font-medium"
                            :class="subAbaClientes === 'cliente' ? 'border-cyan bg-cyan/10 text-gray-900' : 'border-gray-300 text-gray-600'"
                            @click="trocarSub('cliente')"
                        >
                            Cadastro de cliente
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-xs font-medium"
                            :class="subAbaClientes === 'lead' ? 'border-cyan bg-cyan/10 text-gray-900' : 'border-gray-300 text-gray-600'"
                            @click="trocarSub('lead')"
                        >
                            Lead manual
                        </button>
                    </div>

                    <template v-if="subAbaClientes === 'cliente'">
                        <CadastroClienteForm :opcoes="opcoes" />
                        <DarkCard title="Solicitações de cliente" :subtitle="`${clientesFila.total} registro${clientesFila.total !== 1 ? 's' : ''}`">
                            <template #icon>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full"><line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round"/><line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round"/></svg>
                            </template>
                            <CadastroClienteTabela
                                v-if="clientesFila.data.length"
                                :clientes="clientesFila.data"
                                @detalhes="(i) => abrirDetalhes('Cliente #' + i.id, i, [['CNPJ','cnpjFaturamento'],['Razão','razaoSocial'],['Fantasia','nomeFantasia'],['Segmento','segmentoAtuacao'],['UF','estado'],['Telefone','telefone'],['E-mail','email'],['Status','status'],['Solicitante','nomeSolicitante'],['Obs.','observacoes']])"
                                @excluir="(i) => excluir('cadastros.clientes.destroy', i.id)"
                            />
                            <p v-else class="text-sm text-gray-400">Nenhuma solicitação de cliente.</p>
                            <div class="mt-4"><Pagination :meta="clientesFila" :only="['clientesFila']" /></div>
                        </DarkCard>
                    </template>

                    <template v-else>
                        <CadastroLeadForm />
                        <DarkCard title="Leads manuais" :subtitle="`${leads.total} lead${leads.total !== 1 ? 's' : ''}`">
                            <template #icon>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full"><line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round"/><line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round"/></svg>
                            </template>
                            <CadastroLeadTabela
                                v-if="leads.data.length"
                                :leads="leads.data"
                                @excluir="(i) => excluir('cadastros.leads.destroy', i.id)"
                            />
                            <p v-else class="text-sm text-gray-400">Nenhum lead manual.</p>
                            <div class="mt-4"><Pagination :meta="leads" :only="['leads']" /></div>
                        </DarkCard>
                    </template>
                </template>

                <!-- BOBINAS -->
                <template v-else-if="aba === 'bobinas'">
                    <CadastroBobinaForm :prefill="prefillBobina" :meta="meta" />
                    <DarkCard title="Solicitações de bobinas" :subtitle="`${bobinas.total} registro${bobinas.total !== 1 ? 's' : ''}`">
                        <template #icon>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full"><line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round"/><line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round"/></svg>
                        </template>
                        <CadastroBobinaTabela
                            v-if="bobinas.data.length"
                            :bobinas="bobinas.data"
                            @copiar="(i) => { prefillBobina = { ...i }; window.scrollTo({ top: 0, behavior: 'smooth' }); }"
                            @enviar="enviarBobina"
                            @excluir="(i) => excluir('cadastros.bobinas.destroy', i.id)"
                            @detalhes="(i) => abrirDetalhes('Bobina #' + i.id, i, [['Título','tituloPadronizado'],['Nomenclatura','nomenclatura'],['Papel','papel'],['Largura','largura'],['Metragem','metragem'],['Status','status'],['Obs.','observacoes']])"
                        />
                        <p v-else class="text-sm text-gray-400">Nenhuma solicitação de bobina.</p>
                        <div class="mt-4"><Pagination :meta="bobinas" :only="['bobinas']" /></div>
                    </DarkCard>
                </template>

                <!-- ETIQUETAS -->
                <template v-else>
                    <CadastroEtiquetaForm :prefill="prefillEtiqueta" :meta="meta" :opcoes="opcoes.etiquetas" />
                    <DarkCard title="Solicitações de etiquetas" :subtitle="`${etiquetas.total} registro${etiquetas.total !== 1 ? 's' : ''}`">
                        <template #icon>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full"><line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round"/><line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round"/></svg>
                        </template>
                        <CadastroEtiquetaTabela
                            v-if="etiquetas.data.length"
                            :etiquetas="etiquetas.data"
                            @copiar="(i) => { prefillEtiqueta = { ...i }; window.scrollTo({ top: 0, behavior: 'smooth' }); }"
                            @enviar="enviarEtiqueta"
                            @excluir="(i) => excluir('cadastros.etiquetas.destroy', i.id)"
                            @detalhes="(i) => abrirDetalhes('Etiqueta #' + i.id, i, [['Título','tituloPadronizado'],['Nomenclatura','nomenclatura'],['Medidas','medidas'],['Adesivo','tipoAdesivo'],['Saída','saidaRolo'],['Status','status'],['Obs.','observacoes']])"
                        />
                        <p v-else class="text-sm text-gray-400">Nenhuma solicitação de etiqueta.</p>
                        <div class="mt-4"><Pagination :meta="etiquetas" :only="['etiquetas']" /></div>
                    </DarkCard>
                </template>
            </div>
        </div>

        <CadastroDetalhesModal
            :show="detalhes.show"
            :titulo="detalhes.titulo"
            :campos="detalhes.campos"
            @close="detalhes.show = false"
        />
    </AuthenticatedLayout>
</template>
