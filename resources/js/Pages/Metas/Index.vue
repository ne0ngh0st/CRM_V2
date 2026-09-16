<script setup>
import EscopoVazioAviso from '@/Components/EscopoVazioAviso.vue';
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import MetaRankingTabela from '@/Components/Metas/MetaRankingTabela.vue';
import { contarFiltrosAtivos } from '@/utils/filtros.js';

const props = defineProps({
    role: String,
    linhas: { type: Array, default: () => [] },
    grupos: { type: Array, default: () => [] },
    totais: { type: Object, required: true },
    kpis: { type: Object, required: true },
    periodo: { type: Object, required: true },
    filtros: { type: Object, required: true },
    opcoes: { type: Object, required: true },
    podeEditar: { type: Boolean, default: false },
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
    modo: props.filtros.modo || 'mensal',
    busca: props.filtros.busca || '',
    faixa: props.filtros.faixa || '',
    visao_supervisor: props.filtros.visao_supervisor || '',
});

/*
 * ⚠️ Toda prop derivada de `ranking()` mora aqui. Esquecer uma (foi o risco com `grupos`)
 * faz a recarga parcial trazer linhas novas com subtotais velhos — sem erro nem aviso.
 */
const PROPS_DO_RANKING = ['linhas', 'grupos', 'totais', 'kpis', 'periodo', 'filtros', 'podeEditar'];

/*
 * Aba da tabela. ⚠️ FATURAMENTO é o padrão aqui — o INVERSO do Painel (MetaGaugeCard abre
 * em Venda de propósito). A pergunta desta tela é "quanto falta para a meta de faturamento",
 * e as colunas de carteira em aberto só existem nessa aba.
 *
 * ⚠️ Fica FORA de `filtros`: lá ela dispararia uma visita a cada troca e iria no POST da
 * exportação, que grava os filtros no registro para a fila reconstruir a planilha.
 */
const ABAS = [
    { chave: 'faturamento', rotulo: 'Faturamento' },
    { chave: 'venda', rotulo: 'Venda' },
];
const aba = ref('faturamento');

function linhasDoGrupo(grupo) {
    return props.linhas.filter((l) => (l.grupoChave ?? null) === grupo.chave);
}

const subtituloRanking = computed(() => {
    const n = props.linhas.length;
    const equipes = props.grupos.length ? ` em ${props.grupos.length} equipe(s)` : '';
    return `${n} vendedor(es) no filtro${equipes}`;
});

function dataCurta(iso) {
    return iso ? iso.split('-').reverse().join('/') : '';
}

function aplicarFiltros(extra = {}) {
    router.get(route('metas.index'), { ...filtros, ...extra }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: PROPS_DO_RANKING,
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(() => aplicarFiltros(), 300);
}

function setFaixa(faixa) {
    filtros.faixa = filtros.faixa === faixa ? '' : faixa;
    aplicarFiltros();
}

function setModo(modo) {
    filtros.modo = modo;
    aplicarFiltros();
}

/*
 * `ano`/`mes`/`modo` estão de fora: são estruturais (sempre têm valor), então contá-los
 * faria o badge nascer preenchido com a tela intocada. Quem declara o período é o
 * subtítulo; o modo já tem a pastilha no header.
 */
const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, [
    'faixa', 'visao_supervisor',
]));

const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '');

const periodoLabel = computed(() => {
    const ini = props.periodo.inicio?.split('-').reverse().join('/');
    const fim = props.periodo.fim?.split('-').reverse().join('/');
    const d1 = props.periodo.d1 ? ' (D-1)' : '';
    return `${ini} → ${fim}${d1}`;
});

const editando = ref(null);
const form = useForm({
    cod_vendedor: '',
    ano: props.filtros.ano,
    mes: props.filtros.mes,
    meta_faturamento: 0,
    meta_venda: 0,
});

function abrirEdicao(linha) {
    if (!props.podeEditar) return;
    editando.value = linha;
    form.cod_vendedor = linha.codVendedor;
    form.ano = props.filtros.ano;
    form.mes = props.filtros.mes;
    form.meta_faturamento = linha.fatMeta || 0;
    form.meta_venda = linha.vendaMeta || 0;
    form.clearErrors();
}

function fecharEdicao() {
    editando.value = null;
    form.clearErrors();
}

function salvarMeta() {
    form.patch(route('metas.update'), {
        preserveScroll: true,
        onSuccess: () => fecharEdicao(),
    });
}
</script>

<template>
    <Head title="Gerenciar Metas" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Gerenciar Metas" :filtros-ativos="filtrosAtivos">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <circle cx="12" cy="12" r="9" />
                        <circle cx="12" cy="12" r="5" />
                        <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                    </svg>
                </template>
                <template #subtitle>
                    Ranking de atingimento (faturamento + venda/pedidos emitidos). Realizado no período {{ periodoLabel }}.
                </template>
                <template #meta>
                    <span class="rounded border border-white/20 bg-white/10 px-2.5 py-1 text-[0.68rem] font-bold uppercase tracking-wide text-gray-200">
                        {{ filtros.modo === 'acumulado' ? 'Acumulado YTD' : 'Mês a mês' }}
                    </span>
                </template>
                <template #filtrosFixos>
                    <div class="flex w-full flex-col gap-1 sm:min-w-[180px] sm:max-w-[260px] sm:flex-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="filtros.busca"
                            type="search"
                            placeholder="Nome ou código"
                            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                            @input="onBuscaInput"
                        />
                    </div>
                    <div class="flex w-full items-end gap-1 sm:w-auto">
                        <button
                            type="button"
                            class="min-h-11 flex-1 rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition sm:min-h-0 sm:flex-none"
                            :class="filtros.modo === 'mensal' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                            @click="setModo('mensal')"
                        >
                            Mês
                        </button>
                        <button
                            type="button"
                            class="min-h-11 flex-1 rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition sm:min-h-0 sm:flex-none"
                            :class="filtros.modo === 'acumulado' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                            @click="setModo('acumulado')"
                        >
                            Acumulado
                        </button>
                    </div>
                </template>
                <template #filtros>
                    <FilterField v-model="filtros.ano" label="Ano" @update:model-value="aplicarFiltros()">
                        <option v-for="a in opcoes.anos" :key="a" :value="String(a)">{{ a }}</option>
                    </FilterField>
                    <FilterField v-model="filtros.mes" label="Mês" @update:model-value="aplicarFiltros()">
                        <option v-for="m in MESES" :key="m.value" :value="String(m.value)">{{ m.label }}</option>
                    </FilterField>
                    <FilterField
                        v-if="opcoes.supervisores?.length"
                        v-model="filtros.visao_supervisor"
                        label="Supervisor"
                        @update:model-value="aplicarFiltros()"
                    >
                        <option value="">Empresa toda</option>
                        <option v-for="s in opcoes.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">
                            {{ s.nome }}
                        </option>
                    </FilterField>
                </template>
            </PageHero>

            <EscopoVazioAviso :total="kpis.total" recurso="vendedor" />

            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" class="min-w-[84px] flex-1 basis-0" @click="setFaixa('')">
                    <KpiTile :value="kpis.total" label="Total" :tone="filtros.faixa === '' ? 'info' : 'default'" />
                </button>
                <button type="button" class="min-w-[84px] flex-1 basis-0" @click="setFaixa('atingiu')">
                    <KpiTile :value="kpis.atingiu" label="≥ 100%" :tone="filtros.faixa === 'atingiu' ? 'ok' : 'default'" />
                </button>
                <button type="button" class="min-w-[84px] flex-1 basis-0" @click="setFaixa('quase')">
                    <KpiTile :value="kpis.quase" label="80–99%" :tone="filtros.faixa === 'quase' ? 'warn' : 'default'" />
                </button>
                <button type="button" class="min-w-[84px] flex-1 basis-0" @click="setFaixa('abaixo')">
                    <KpiTile :value="kpis.abaixo" label="< 80%" :tone="filtros.faixa === 'abaixo' ? 'danger' : 'default'" />
                </button>
                <button type="button" class="min-w-[84px] flex-1 basis-0" @click="setFaixa('sem_meta')">
                    <KpiTile :value="kpis.semMeta" label="Sem meta" :tone="filtros.faixa === 'sem_meta' ? 'info' : 'default'" />
                </button>
            </div>

            <DarkCard title="Ranking" :subtitle="subtituloRanking">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                        <path d="M4 19h16M7 16V9M12 16V5M17 16v-4" stroke-linecap="round" />
                    </svg>
                </template>
                <template #actions>
                    <div class="flex items-center gap-2">
                        <div class="flex overflow-hidden rounded border border-gray-600">
                            <button
                                v-for="opcao in ABAS"
                                :key="opcao.chave"
                                type="button"
                                class="min-h-11 px-2 py-1 text-xs font-medium transition sm:min-h-0"
                                :class="aba === opcao.chave ? 'bg-white/20 text-white' : 'text-gray-300 hover:bg-white/10'"
                                @click="aba = opcao.chave"
                            >
                                {{ opcao.rotulo }}
                            </button>
                        </div>
                        <ExportarExcelButton
                            rota="metas.exportar"
                            :filtros="filtros"
                            :tem-filtros-ativos="temFiltrosAtivos"
                        />
                    </div>
                </template>

                <p v-if="aba === 'faturamento'" class="mb-3 text-xs text-gray-500">
                    <template v-if="periodo.abertoAplicavel">
                        <strong class="font-semibold text-gray-700">Falta vender</strong> = meta − faturado − pedidos em aberto
                        com previsão até o fim do mês (carteira de {{ dataCurta(periodo.abertoEm) }}).
                    </template>
                    <template v-else>
                        A carteira em aberto é uma foto de hoje — não existe versão histórica, por isso
                        "Em aberto" e "Falta vender" ficam vazios em meses já fechados.
                    </template>
                </p>

                <p v-if="!linhas.length" class="px-1 py-8 text-center text-sm text-gray-400">
                    Nenhum vendedor no filtro.
                </p>

                <!-- Por equipe (admin/diretor na visão da empresa): uma tabela por equipe. -->
                <div v-else-if="grupos.length" class="space-y-4">
                    <section v-for="grupo in grupos" :key="grupo.chave ?? 'sem'" class="overflow-hidden rounded border border-gray-200">
                        <header class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 text-gray-400">
                                <circle cx="9" cy="7" r="3" />
                                <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                                <circle cx="17" cy="8" r="2.5" />
                                <path d="M16 14.2c2.9.4 5 2.7 5 5.8" stroke-linecap="round" />
                            </svg>
                            <strong class="text-sm text-gray-800">{{ grupo.nome }}</strong>
                            <span class="text-xs text-gray-400">({{ linhasDoGrupo(grupo).length }})</span>
                        </header>
                        <MetaRankingTabela
                            :linhas="linhasDoGrupo(grupo)"
                            :totais="grupo.subtotais"
                            :aba="aba"
                            :pode-editar="podeEditar"
                            rotulo-totais="Subtotal da equipe"
                            @editar="abrirEdicao"
                        />
                    </section>

                    <section class="overflow-hidden rounded border-2 border-gray-300">
                        <MetaRankingTabela
                            :linhas="[]"
                            :totais="totais"
                            :aba="aba"
                            :pode-editar="podeEditar"
                            rotulo-totais="Total geral"
                        />
                    </section>
                </div>

                <MetaRankingTabela
                    v-else
                    :linhas="linhas"
                    :totais="totais"
                    :aba="aba"
                    :pode-editar="podeEditar"
                    @editar="abrirEdicao"
                />
            </DarkCard>
        </div>

        <Modal :show="!!editando" max-width="md" @close="fecharEdicao">
            <form v-if="editando" class="p-6" @submit.prevent="salvarMeta">
                <h2 class="text-lg font-semibold text-gray-800">Editar metas</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ editando.nome }} · {{ editando.codVendedor }} ·
                    {{ MESES.find((m) => m.value === Number(filtros.mes))?.label }}/{{ filtros.ano }}
                </p>

                <div class="mt-4">
                    <InputLabel for="meta_faturamento" value="Meta faturamento (R$)" />
                    <TextInput
                        id="meta_faturamento"
                        v-model="form.meta_faturamento"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 block w-full"
                        required
                    />
                    <InputError :message="form.errors.meta_faturamento" class="mt-1" />
                </div>

                <div class="mt-4">
                    <InputLabel for="meta_venda" value="Meta venda / pedidos emitidos (R$)" />
                    <TextInput
                        id="meta_venda"
                        v-model="form.meta_venda"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 block w-full"
                        required
                    />
                    <InputError :message="form.errors.meta_venda" class="mt-1" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="fecharEdicao">Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="form.processing">Salvar</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
