<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    role: String,
    linhas: { type: Array, default: () => [] },
    kpis: { type: Object, required: true },
    filtros: { type: Object, required: true },
    opcoes: { type: Object, required: true },
    alertas: { type: Object, required: true },
});

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

            <DarkCard title="Equipe" :subtitle="`${linhas.length} na listagem`">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                        <circle cx="9" cy="7" r="3" />
                        <path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6" stroke-linecap="round" />
                        <circle cx="17" cy="8" r="2.5" />
                        <path d="M16 14.2c2.9.4 5 2.7 5 5.8" stroke-linecap="round" />
                    </svg>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                                <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                                <th class="px-3 py-2.5 font-semibold">Código</th>
                                <th class="px-3 py-2.5 font-semibold">Status</th>
                                <th class="px-3 py-2.5 font-semibold">Lig. mês</th>
                                <th class="px-3 py-2.5 font-semibold">Obs. mês</th>
                                <th class="px-3 py-2.5 font-semibold">Última ligação</th>
                                <th class="px-3 py-2.5 font-semibold">Última obs.</th>
                                <th class="px-3 py-2.5 font-semibold">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr
                                v-for="linha in linhas"
                                :key="linha.userId"
                                class="divide-x divide-gray-200 hover:bg-gray-50/60"
                            >
                                <td class="px-3 py-2.5 text-center align-middle font-medium text-gray-800">{{ linha.nome }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ linha.codVendedor || '—' }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <StatusPill :tone="linha.atencao ? 'danger' : 'ok'">
                                        {{ linha.atencao ? 'Atenção' : 'Em dia' }}
                                    </StatusPill>
                                    <div v-if="linha.motivoAtencao" class="mt-1 text-[0.65rem] text-gray-400">
                                        {{ linha.motivoAtencao }}
                                    </div>
                                </td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ linha.ligacoesMes }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ linha.observacoesMes }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ formatData(linha.ultimaLigacao) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ formatData(linha.ultimaObservacao) }}</td>
                                <td class="px-3 py-2.5 text-center align-middle">
                                    <Link
                                        v-if="linha.codVendedor"
                                        :href="route('carteira.index', { visao_vendedor: linha.codVendedor })"
                                        title="Ver carteira"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:text-teal"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                            <path d="M3 7h18v10H3z" />
                                            <path d="M3 10h18" stroke-linecap="round" />
                                        </svg>
                                    </Link>
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
