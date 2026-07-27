<script setup>
import { computed } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';

const props = defineProps({
    carteiraSegmento: {
        type: Object,
        required: true,
    },
});

const totalAtivos = computed(() => props.carteiraSegmento.dentroSegmento.ativos + props.carteiraSegmento.foraSegmento.ativos);
const totalInativando = computed(() => props.carteiraSegmento.dentroSegmento.inativando + props.carteiraSegmento.foraSegmento.inativando);
const totalInativos = computed(() => props.carteiraSegmento.dentroSegmento.inativos + props.carteiraSegmento.foraSegmento.inativos);

const grupos = computed(() => [
    { chave: 'dentro', titulo: 'Dentro do segmento', dados: props.carteiraSegmento.dentroSegmento, corBarra: 'text-emerald-600' },
    { chave: 'fora', titulo: 'Fora do segmento', dados: props.carteiraSegmento.foraSegmento, corBarra: 'text-red-500' },
]);

const linhasStatus = (dados) => [
    { label: 'Ativos', valor: dados.ativos, pct: dados.pctAtivos, dot: 'bg-emerald-500' },
    { label: 'Inativando', valor: dados.inativando, pct: dados.pctInativando, dot: 'bg-amber-500' },
    { label: 'Inativos', valor: dados.inativos, pct: dados.pctInativos, dot: 'bg-red-500' },
];
</script>

<template>
    <DarkCard
        title="Carteira por Segmento"
        :subtitle="`${carteiraSegmento.total} clientes · ${carteiraSegmento.pctDentro}% no segmento`"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
            </svg>
        </template>

        <div v-if="carteiraSegmento.total > 0" class="flex flex-col gap-4">
            <div class="flex flex-wrap gap-2">
                <KpiTile :value="totalAtivos" label="Ativos" tone="ok" />
                <KpiTile :value="totalInativando" label="Inativando" tone="warn" />
                <KpiTile :value="totalInativos" label="Inativos" tone="danger" />
                <KpiTile :value="`${carteiraSegmento.pctDentro}%`" label="No segmento" tone="info" />
            </div>

            <div class="flex items-center gap-3 sm:gap-4">
                <div class="shrink-0">
                    <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">No segmento</p>
                    <p class="text-lg font-bold text-emerald-600">
                        {{ carteiraSegmento.dentroSegmento.total }}
                        <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctDentro }}%)</span>
                    </p>
                </div>
                <div class="flex h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                    <div class="bg-emerald-500" :style="{ width: carteiraSegmento.pctDentro + '%' }" />
                    <div class="bg-red-400" :style="{ width: carteiraSegmento.pctFora + '%' }" />
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Fora do segmento</p>
                    <p class="text-lg font-bold text-red-500">
                        {{ carteiraSegmento.foraSegmento.total }}
                        <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctFora }}%)</span>
                    </p>
                </div>
            </div>

            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" /> Ativos ≤ 290 dias</span>
                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" /> Inativando 291–365 dias</span>
                <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-red-500" /> Inativos &gt; 365 dias ou sem compra</span>
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div v-for="grupo in grupos" :key="grupo.chave" class="rounded border border-gray-200 p-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-800">{{ grupo.titulo }}</p>
                        <p class="text-xs text-gray-400">{{ grupo.dados.total }} clientes</p>
                    </div>

                    <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="bg-emerald-500" :style="{ width: grupo.dados.pctAtivos + '%' }" />
                        <div class="bg-amber-500" :style="{ width: grupo.dados.pctInativando + '%' }" />
                        <div class="bg-red-500" :style="{ width: grupo.dados.pctInativos + '%' }" />
                    </div>

                    <table class="mt-3 w-full text-sm">
                        <thead>
                            <tr class="text-[0.65rem] uppercase tracking-wide text-gray-400">
                                <th class="text-left font-semibold">Status</th>
                                <th class="text-right font-semibold">Clientes</th>
                                <th class="text-right font-semibold">% do grupo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="linha in linhasStatus(grupo.dados)" :key="linha.label" class="border-t border-gray-100">
                                <td class="py-1.5">
                                    <span class="inline-flex items-center gap-1.5 text-gray-700">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="linha.dot" />
                                        {{ linha.label }}
                                    </span>
                                </td>
                                <td class="py-1.5 text-right font-semibold text-gray-800">{{ linha.valor }}</td>
                                <td class="py-1.5 text-right text-gray-500">{{ linha.pct }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-gray-400">Nenhum cliente na carteira.</p>
    </DarkCard>
</template>
