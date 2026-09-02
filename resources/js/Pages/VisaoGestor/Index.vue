<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { CANAIS_CONTATO, ROTULOS_CANAL_CONTATO, ROTULOS_CANAL_CURTO } from '@/constants/contatos.js';

const props = defineProps({
    role: String,
    linhas: { type: Array, default: () => [] },
    kpis: { type: Object, required: true },
    filtros: { type: Object, required: true },
    opcoes: { type: Object, required: true },
    alertas: { type: Object, required: true },
});

/*
 * Quebra por canal para os KPIs do topo. Vem pronta do backend.
 */
const canais = CANAIS_CONTATO.map((canal) => ({ canal, rotulo: ROTULOS_CANAL_CONTATO[canal] ?? canal }));

/**
 * "12 Tel. · 5 Whats" — resumo do canal na própria célula de "Lig. mês", em vez de
 * quatro colunas novas numa tabela que já tem oito. Canal zerado não aparece: o que
 * o gestor procura aqui é quem só usa um canal, e linha cheia de "0" esconde isso.
 */
function resumoCanais(porCanal) {
    if (!porCanal) return '';

    return CANAIS_CONTATO
        .filter((canal) => (porCanal[canal] ?? 0) > 0)
        .map((canal) => `${porCanal[canal]} ${ROTULOS_CANAL_CURTO[canal] ?? canal}`)
        .join(' · ');
}

const filtros = reactive({
    busca: props.filtros.busca || '',
    so_atencao: props.filtros.so_atencao ? '1' : '',
    visao_supervisor: props.filtros.visao_supervisor || '',
});

function aplicarFiltros() {
    router.get(route('visao-gestor.index'), {
        busca: filtros.busca || undefined,
        so_atencao: filtros.so_atencao ? 1 : undefined,
        visao_supervisor: filtros.visao_supervisor || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['linhas', 'kpis', 'filtros'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(() => aplicarFiltros(), 300);
}

function formatData(iso) {
    if (!iso) return 'Nunca';
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Visão do Gestor" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Visão do Gestor">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <circle cx="9" cy="7" r="3" />
                        <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                        <path d="M16 8l2 2 4-4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    Produtividade da equipe no mês: ligações e observações. Atenção se sem ligação ≥{{ alertas.diasLigacao }} dias
                    ou sem observação ≥{{ alertas.diasObservacao }} dias.
                </template>
                <template #filtros>
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
                    <FilterField
                        v-if="opcoes.supervisores?.length"
                        v-model="filtros.visao_supervisor"
                        label="Supervisor"
                        @update:model-value="aplicarFiltros"
                    >
                        <option value="">Empresa toda</option>
                        <option v-for="s in opcoes.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">
                            {{ s.nome }}
                        </option>
                    </FilterField>
                    <FilterField
                        v-model="filtros.so_atencao"
                        label="Status"
                        @update:model-value="aplicarFiltros"
                    >
                        <option value="">Todos</option>
                        <option value="1">Só atenção</option>
                    </FilterField>
                </template>
            </PageHero>

            <div class="mb-4 flex flex-wrap gap-2">
                <KpiTile :value="kpis.vendedores" label="Vendedores" />
                <KpiTile :value="kpis.ligacoesMes" label="Ligações (mês)" tone="info" />
                <KpiTile :value="kpis.observacoesMes" label="Observações (mês)" tone="info" />
                <KpiTile :value="kpis.atencao" label="Precisam atenção" :tone="kpis.atencao > 0 ? 'danger' : 'ok'" />
            </div>

            <div v-if="kpis.ligacoesPorCanal" class="mb-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Contatos do mês por canal</p>
                <div class="flex flex-wrap gap-2">
                    <KpiTile
                        v-for="c in canais"
                        :key="c.canal"
                        :value="kpis.ligacoesPorCanal[c.canal] ?? 0"
                        :label="c.rotulo"
                    />
                </div>
            </div>

            <DarkCard title="Equipe" :subtitle="`${linhas.length} na listagem`">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                        <circle cx="9" cy="7" r="3" />
                        <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                        <circle cx="17" cy="8" r="2.5" />
                        <path d="M16 14.2c2.9.4 5 2.7 5 5.8" stroke-linecap="round" />
                    </svg>
                </template>

                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead>
                            <tr class="tbl-head-row">
                                <th class="tbl-th">Vendedor</th>
                                <th class="tbl-th">Código</th>
                                <th class="tbl-th">Status</th>
                                <th class="tbl-th">Lig. mês</th>
                                <th class="tbl-th">Obs. mês</th>
                                <th class="tbl-th">Última ligação</th>
                                <th class="tbl-th">Última obs.</th>
                                <th class="tbl-th">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="tbl-body">
                            <tr
                                v-for="linha in linhas"
                                :key="linha.userId"
                                class="tbl-row"
                            >
                                <td class="tbl-td font-medium text-gray-800">{{ linha.nome }}</td>
                                <td class="tbl-td">{{ linha.codVendedor || '—' }}</td>
                                <td class="tbl-td">
                                    <StatusPill :tone="linha.atencao ? 'danger' : 'ok'" size="sm">
                                        {{ linha.atencao ? 'Atenção' : 'Em dia' }}
                                    </StatusPill>
                                    <div v-if="linha.motivoAtencao" class="tbl-sub mt-1">
                                        {{ linha.motivoAtencao }}
                                    </div>
                                </td>
                                <td class="tbl-td">
                                    <span class="tbl-main">{{ linha.ligacoesMes }}</span>
                                    <span v-if="resumoCanais(linha.ligacoesPorCanal)" class="tbl-sub">
                                        {{ resumoCanais(linha.ligacoesPorCanal) }}
                                    </span>
                                </td>
                                <td class="tbl-td">{{ linha.observacoesMes }}</td>
                                <td class="tbl-td">{{ formatData(linha.ultimaLigacao) }}</td>
                                <td class="tbl-td">{{ formatData(linha.ultimaObservacao) }}</td>
                                <td class="tbl-td">
                                    <div class="tbl-acoes">
                                        <Link
                                            v-if="linha.codVendedor"
                                            :href="route('carteira.index', { visao_vendedor: linha.codVendedor })"
                                            title="Ver carteira"
                                            class="tbl-acao tbl-acao-neutro"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                <path d="M3 7h18v10H3z" />
                                                <path d="M3 10h18" stroke-linecap="round" />
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!linhas.length">
                                <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-400">
                                    Nenhum vendedor no filtro.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
