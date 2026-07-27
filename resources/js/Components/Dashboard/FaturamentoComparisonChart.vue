<script setup>
import { computed, ref } from 'vue';
import { Line } from 'vue-chartjs';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Legend,
} from 'chart.js';
import DarkCard from '@/Components/DarkCard.vue';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
    faturamentoComparacao: {
        type: Object,
        required: true,
    },
});

const expandido = ref(false);

const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

const chartData = computed(() => ({
    labels: meses,
    datasets: [
        {
            label: String(props.faturamentoComparacao.anoAnterior),
            data: props.faturamentoComparacao.valoresAnoAnterior,
            borderColor: '#f59e0b',
            backgroundColor: '#f59e0b',
            borderDash: [6, 4],
            tension: 0.3,
        },
        {
            label: String(props.faturamentoComparacao.anoAtual),
            data: props.faturamentoComparacao.valoresAnoAtual,
            borderColor: '#005A6F',
            backgroundColor: '#005A6F',
            tension: 0.3,
        },
    ],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom' },
        tooltip: {
            callbacks: {
                label: (ctx) => `${ctx.dataset.label}: ${new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        y: {
            ticks: {
                callback: (value) => new Intl.NumberFormat('pt-BR', { notation: 'compact', compactDisplay: 'short' }).format(value),
            },
        },
    },
};
</script>

<template>
    <DarkCard title="Comparação de Faturamento" subtitle="Ano atual vs. ano anterior, mês a mês">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>
        <template #actions>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-gray-600 px-2 py-1 text-xs font-medium text-gray-200 transition hover:bg-white/10"
                @click="expandido = !expandido"
            >
                {{ expandido ? 'Ocultar evolução ▲' : 'Ver evolução ▾' }}
            </button>
        </template>

        <div v-if="expandido" class="h-72">
            <Line :data="chartData" :options="chartOptions" />
        </div>
        <p v-else class="text-sm text-gray-400">Clique em "Ver evolução" para comparar mês a mês.</p>
    </DarkCard>
</template>
