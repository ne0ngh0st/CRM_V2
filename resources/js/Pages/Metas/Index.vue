<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    role: String,
    linhas: { type: Array, default: () => [] },
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

function aplicarFiltros(extra = {}) {
    router.get(route('metas.index'), { ...filtros, ...extra }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['linhas', 'totais', 'kpis', 'periodo', 'filtros', 'podeEditar'],
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

function formatMoney(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatPct(v) {
    if (v === null || v === undefined) return '—';
    return `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
}

function tonePct(v) {
    if (v === null || v === undefined) return 'neutral';
    if (v >= 100) return 'ok';
    if (v >= 80) return 'warn';
    return 'danger';
}

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
            <PageHero title="Gerenciar Metas">
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
                    <div class="flex min-w-[180px] flex-1 flex-col gap-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="filtros.busca"
                            type="search"
                            placeholder="Nome ou código"
                            class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                            @input="onBuscaInput"
                        />
                    </div>
                    <div class="flex items-end gap-1 pb-0.5">
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                            :class="filtros.modo === 'mensal' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                            @click="setModo('mensal')"
                        >
                            Mês
                        </button>
                        <button
                            type="button"
                            class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                            :class="filtros.modo === 'acumulado' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                            @click="setModo('acumulado')"
                        >
                            Acumulado
                        </button>
                    </div>
                </template>
            </PageHero>

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

            <DarkCard title="Ranking" :subtitle="`${linhas.length} vendedor(es) no filtro`">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                        <path d="M4 19h16M7 16V9M12 16V5M17 16v-4" stroke-linecap="round" />
                    </svg>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                                <th class="px-3 py-2.5 font-semibold">Código</th>
                                <th class="px-3 py-2.5 font-semibold">Fat. realizado</th>
                                <th class="px-3 py-2.5 font-semibold">Fat. meta</th>
                                <th class="px-3 py-2.5 font-semibold">Fat. %</th>
                                <th class="px-3 py-2.5 font-semibold">Venda realizado</th>
                                <th class="px-3 py-2.5 font-semibold">Venda meta</th>
                                <th class="px-3 py-2.5 font-semibold">Venda %</th>
                                <th v-if="podeEditar" class="px-3 py-2.5 font-semibold">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr
                                v-for="linha in linhas"
                                :key="linha.userId"
                                class="divide-x divide-gray-200 hover:bg-gray-50/60"
                            >
                                <td class="px-3 py-2.5 text-center align-middle font-medium text-gray-800">{{ linha.nome }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ linha.codVendedor }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ formatMoney(linha.fatRealizado) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ formatMoney(linha.fatMeta) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <StatusPill v-if="linha.fatPct !== null" :tone="tonePct(linha.fatPct)">{{ formatPct(linha.fatPct) }}</StatusPill>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ formatMoney(linha.vendaRealizado) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ formatMoney(linha.vendaMeta) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <StatusPill v-if="linha.vendaPct !== null" :tone="tonePct(linha.vendaPct)">{{ formatPct(linha.vendaPct) }}</StatusPill>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td v-if="podeEditar" class="px-3 py-2.5 text-center align-middle">
                                    <button
                                        type="button"
                                        title="Editar metas do mês"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:text-teal"
                                        @click="abrirEdicao(linha)"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                            <path d="M4 20h4l10-10-4-4L4 16v4z" stroke-linejoin="round" />
                                            <path d="M13 7l4 4" stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!linhas.length">
                                <td :colspan="podeEditar ? 9 : 8" class="px-3 py-8 text-center text-sm text-gray-400">
                                    Nenhum vendedor no filtro.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="linhas.length">
                            <tr class="divide-x divide-gray-200 border-t-2 border-gray-300 bg-gray-50 font-semibold text-gray-800">
                                <td class="px-3 py-2.5 text-center align-middle" colspan="2">Totais</td>
                                <td class="px-3 py-2.5 text-center align-middle">{{ formatMoney(totais.fatRealizado) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">{{ formatMoney(totais.fatMeta) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <StatusPill v-if="totais.fatPct !== null" :tone="tonePct(totais.fatPct)">{{ formatPct(totais.fatPct) }}</StatusPill>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-3 py-2.5 text-center align-middle">{{ formatMoney(totais.vendaRealizado) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">{{ formatMoney(totais.vendaMeta) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <StatusPill v-if="totais.vendaPct !== null" :tone="tonePct(totais.vendaPct)">{{ formatPct(totais.vendaPct) }}</StatusPill>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td v-if="podeEditar" class="px-3 py-2.5" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
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
