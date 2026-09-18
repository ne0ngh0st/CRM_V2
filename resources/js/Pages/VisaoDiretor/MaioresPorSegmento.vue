<script setup>
/**
 * Visão Diretor → Maiores por Segmento.
 *
 * Substitui a planilha "MAIORES POR SEGMENTO - CRM.xlsx": as maiores redes do MERCADO em
 * cada segmento, e onde a Autopel está nelas. Uma aba por segmento, igual à planilha,
 * mais o Resumo (a aba DASHBOARD). Nome, UF, filiais e observação são digitados; lojas,
 * status, atendimento e faturamento vêm do CRM (ver docs/visao-diretor.md). Todos os
 * números levam a uma lista viva da Carteira.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import ResumoSegmentosCard from '@/Components/VisaoDiretor/ResumoSegmentosCard.vue';
import ContasSegmentoTabela from '@/Components/VisaoDiretor/ContasSegmentoTabela.vue';
import ContaEstrategicaModal from '@/Components/VisaoDiretor/ContaEstrategicaModal.vue';
import { useConfirmacao } from '@/composables/useConfirmacao';
import { ROTULOS_STATUS_CONTA, STATUS_CONTA } from '@/constants/visaoDiretor';
import { contarFiltrosAtivos } from '@/utils/filtros';
import { formatBRL, formatBRLCurto, formatInteiro, formatPercentual } from '@/utils/formato';

const props = defineProps({
    dados: { type: Object, required: true },
    filtros: { type: Object, required: true },
    usuarios: { type: Array, default: () => [] },
    segmentosDisponiveis: { type: Array, default: () => [] },
});

const filtros = reactive({
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    uf: props.filtros.uf || '',
    busca: props.filtros.busca || '',
});

const PROPS_DA_LISTA = ['dados', 'filtros'];

function queryDaPagina(segmento = filtros.segmento) {
    return {
        segmento: segmento || undefined,
        status: filtros.status || undefined,
        uf: filtros.uf || undefined,
        busca: filtros.busca || undefined,
    };
}

function aplicarFiltros() {
    router.get(route('visao-diretor.maiores.index'), queryDaPagina(), {
        preserveState: true, preserveScroll: true, replace: true, only: PROPS_DA_LISTA,
    });
}

let atrasoBusca;
function onBusca() {
    clearTimeout(atrasoBusca);
    atrasoBusca = setTimeout(aplicarFiltros, 300);
}

/**
 * Troca de aba é local: as seis tabelas já vieram no payload. Ir ao servidor só
 * para esconder cinco delas atrasaria o clique (Regra nº 9) e faria a tela parecer
 * o Excel com um loading no meio.
 *
 * A URL acompanha (`?segmento=109`) para o F5 e o link compartilhado abrirem a
 * mesma aba. `replaceState` em cima do estado do Inertia, para o voltar do
 * navegador não perder a página.
 */
function trocarAba(codigo) {
    filtros.segmento = codigo;
    const qs = new URLSearchParams();
    const q = queryDaPagina(codigo);
    Object.entries(q).forEach(([k, v]) => { if (v) qs.set(k, v); });
    const url = route('visao-diretor.maiores.index') + (qs.toString() ? `?${qs}` : '');
    window.history.replaceState(window.history.state, '', url);
}

function filtrarSegmento(codigo) {
    trocarAba(codigo);
}

const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, ['status', 'uf']));
const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '' || filtros.segmento !== '');

const abaAtiva = computed(() => {
    if (! filtros.segmento) return null;

    return props.dados.segmentos.find((s) => s.codigo === filtros.segmento) ?? null;
});

const kpis = computed(() => abaAtiva.value?.resumo ?? props.dados.kpis);
const periodo = computed(() => props.dados.periodo);

/**
 * "Faturamento atualizado em 18/09 10:05". Sem data = o rollup nunca rodou, e isso tem
 * que aparecer — faturamento zerado sem aviso seria lido como "ninguém compra".
 */
const frescor = computed(() => {
    const em = periodo.value.rollupAtualizadoEm;

    if (! em) return 'Faturamento ainda não calculado';

    return `Faturamento atualizado em ${new Date(em).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}`;
});

// ─── Especialista do segmento ────────────────────────────────────────────────
function trocarEspecialista(segmento, userId) {
    router.patch(route('visao-diretor.maiores.especialista', segmento.id), {
        especialista_user_id: userId || null,
    }, { preserveScroll: true, preserveState: true, only: PROPS_DA_LISTA });
}

// ─── Cadastro / edição ───────────────────────────────────────────────────────
const modalAberto = ref(false);
const contaEmEdicao = ref(null);
const segmentoDaNova = ref(null);

function novaConta(segmento = null) {
    contaEmEdicao.value = null;
    segmentoDaNova.value = segmento?.id ?? abaAtiva.value?.id ?? null;
    modalAberto.value = true;
}

function editar(conta, segmento) {
    contaEmEdicao.value = { ...conta, segmentoId: segmento.id };
    modalAberto.value = true;
}

const { confirmacao, confirmar, aoConfirmar, aoCancelar } = useConfirmacao();

async function excluir(conta) {
    const ok = await confirmar({
        titulo: 'Excluir conta',
        mensagem: `A conta "${conta.nome}" sai da Visão Diretor.`,
        detalhe: 'Os clientes continuam na Carteira — só a conta-alvo e os vínculos dela são apagados.',
        rotuloConfirmar: 'Excluir',
        tom: 'danger',
    });

    if (! ok) return;

    router.delete(route('visao-diretor.maiores.destroy', conta.id), { preserveScroll: true, only: PROPS_DA_LISTA });
}
</script>

<template>
    <Head title="Maiores por Segmento" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Maiores por Segmento" :filtros-ativos="filtrosAtivos">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M3.6 3.8v16.6h16.8" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M6.8 15.6l3.9-4.4 3.2 2.8 5.3-6.2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M15.6 7.8h3.6v3.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    As maiores redes do mercado em cada segmento e onde a Autopel está nelas.
                    Lojas, status e atendimento vêm da Carteira; faturamento dos 12 meses fechados ({{ periodo.rotulo }}).
                </template>
                <template #filtrosFixos>
                    <div class="flex w-full flex-col gap-1 sm:min-w-[180px] sm:max-w-[260px] sm:flex-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="filtros.busca"
                            type="search"
                            placeholder="Nome da rede"
                            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                            @input="onBusca"
                        />
                    </div>
                </template>
                <template #filtros>
                    <FilterField v-model="filtros.status" label="Status" @update:model-value="aplicarFiltros">
                        <option value="">Todos</option>
                        <option v-for="s in STATUS_CONTA" :key="s" :value="s">{{ ROTULOS_STATUS_CONTA[s] }}</option>
                    </FilterField>
                    <FilterField v-model="filtros.uf" label="UF" @update:model-value="aplicarFiltros">
                        <option value="">Todas</option>
                        <option v-for="uf in dados.opcoes.ufs" :key="uf" :value="uf">{{ uf }}</option>
                    </FilterField>
                </template>
            </PageHero>

            <!--
                Igual à planilha: uma aba por segmento, mais o Resumo (a aba DASHBOARD).
                `overflow-x-auto` + nowrap para caber as seis no celular sem virar duas
                fileiras — no Excel as abas também rolam na horizontal.
            -->
            <div class="flex gap-1 overflow-x-auto border-b border-gray-300" role="tablist" aria-label="Segmentos">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="! filtros.segmento"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="! filtros.segmento ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba('')"
                >Resumo</button>
                <button
                    v-for="s in dados.segmentos"
                    :key="s.codigo"
                    type="button"
                    role="tab"
                    :aria-selected="filtros.segmento === s.codigo"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium uppercase tracking-wide transition"
                    :class="filtros.segmento === s.codigo ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba(s.codigo)"
                >{{ s.nome }}</button>
            </div>

            <div class="flex flex-wrap gap-2">
                <KpiTile :value="kpis.contas" label="Contas-alvo" />
                <KpiTile :value="kpis.ativo" :label="ROTULOS_STATUS_CONTA.ativo" tone="ok" />
                <KpiTile :value="kpis.inativando" :label="ROTULOS_STATUS_CONTA.inativando" tone="warn" />
                <KpiTile :value="kpis.inativo + kpis.lead" label="A trabalhar + lead" tone="danger" />
                <KpiTile :value="formatInteiro(kpis.filiaisMercado)" label="Filiais no mercado" />
                <KpiTile :value="formatInteiro(kpis.lojas)" label="Nossas lojas" tone="info" />
                <KpiTile :value="formatPercentual(kpis.penetracao)" label="Penetração" tone="info" />
                <div :title="formatBRL(kpis.fat12m)">
                    <KpiTile :value="formatBRLCurto(kpis.fat12m)" label="Fat. 12 meses" compact />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-gray-500">{{ frescor }}</p>
                <div class="flex items-center gap-2">
                    <ExportarExcelButton
                        rota="visao-diretor.maiores.exportar"
                        :filtros="filtros"
                        :tem-filtros-ativos="temFiltrosAtivos"
                    />
                    <button
                        type="button"
                        class="inline-flex min-h-9 items-center gap-1 rounded bg-teal px-3 text-xs font-semibold text-white transition hover:bg-navy"
                        @click="novaConta()"
                    >+ Nova conta</button>
                </div>
            </div>

            <ResumoSegmentosCard
                v-if="! abaAtiva"
                :linhas="dados.resumoPorSegmento"
                :segmento-ativo="filtros.segmento"
                :periodo="periodo"
                @filtrar="filtrarSegmento"
            />

            <DarkCard
                v-else
                :title="abaAtiva.nome"
                :subtitle="`${abaAtiva.resumo.contas} contas · ${formatInteiro(abaAtiva.resumo.lojas)} lojas nossas de ${formatInteiro(abaAtiva.resumo.filiaisMercado)} no mercado`"
            >
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                        <path d="M4 20V9l8-5 8 5v11" stroke-linejoin="round" />
                        <path d="M9 20v-6h6v6" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #actions>
                    <label class="flex items-center gap-2 text-[0.68rem] uppercase tracking-wide text-gray-400">
                        Especialista
                        <select
                            class="rounded border-gray-600 bg-corp-dark py-1 pl-2 pr-7 text-xs normal-case tracking-normal text-white focus:border-cyan focus:ring-cyan"
                            :value="abaAtiva.especialista?.id ?? ''"
                            @change="trocarEspecialista(abaAtiva, $event.target.value)"
                        >
                            <option value="">— ninguém —</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.nome }}</option>
                        </select>
                    </label>
                </template>

                <p v-if="! abaAtiva.contas.length" class="px-3 py-10 text-center text-sm text-gray-400">
                    Nenhuma conta neste segmento.
                </p>
                <ContasSegmentoTabela
                    v-else
                    :contas="abaAtiva.contas"
                    @editar="(conta) => editar(conta, abaAtiva)"
                    @excluir="excluir"
                />
            </DarkCard>
        </div>

        <ContaEstrategicaModal
            :show="modalAberto"
            :conta="contaEmEdicao"
            :segmento-inicial="segmentoDaNova"
            :segmentos="segmentosDisponiveis"
            @close="modalAberto = false"
        />

        <ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
    </AuthenticatedLayout>
</template>
