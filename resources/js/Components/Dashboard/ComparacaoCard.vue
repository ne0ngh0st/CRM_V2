<script setup>
/**
 * Comparação ano vs. ano, mês a mês, com duas abas: VENDA e FATURAMENTO.
 *
 * ⚠️ VENDA é a aba padrão, e a troca em relação ao card antigo (só faturamento) é
 * deliberada: venda é pedido emitido — o que o vendedor consegue influenciar hoje.
 * Faturamento é consequência, e chega depois. Para quem opera, o número que importa é o
 * primeiro.
 *
 * ⚠️ As duas séries vêm juntas do servidor, e o alternador NÃO vai ao servidor. Cada aba
 * custa duas agregações cacheadas; buscá-las no clique tornaria a troca lenta justamente
 * para quem alterna. Ver o comentário em DashboardController.
 *
 * ⚠️ O período das duas abas é o MESMO (D-1 no mês corrente, D-3 na segunda) — o card fica
 * ao lado do KPI "Valor no mês", que usa essa janela. Duas convenções de período na mesma
 * tela produziriam dois números diferentes para o mesmo mês.
 */
import DarkCard from '@/Components/DarkCard.vue';
import { computed, ref } from 'vue';
import {
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { Line } from 'vue-chartjs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
    vendaComparacao: { type: Object, default: null },
    faturamentoComparacao: { type: Object, default: null },
});

const ABAS = [
    {
        chave: 'venda',
        rotulo: 'Venda',
        subtitulo: 'Pedidos emitidos · ano atual vs. anterior',
    },
    {
        chave: 'faturamento',
        rotulo: 'Faturamento',
        subtitulo: 'Notas emitidas · ano atual vs. anterior',
    },
];

const aba = ref(props.vendaComparacao ? 'venda' : 'faturamento');
const expandido = ref(true);

const abasDisponiveis = computed(() =>
    ABAS.filter((a) => (a.chave === 'venda' ? props.vendaComparacao : props.faturamentoComparacao)),
);

const dados = computed(() =>
    aba.value === 'venda' ? props.vendaComparacao : props.faturamentoComparacao,
);

const subtitulo = computed(() => ABAS.find((a) => a.chave === aba.value)?.subtitulo ?? '');

/**
 * ⚠️ Declara quando a série do ano anterior está incompleta, em vez de deixar a linha
 * rente ao zero parecer defeito.
 *
 * O histórico de pedidos emitidos ainda não foi carregado (existe no legado), então hoje o
 * ano anterior da aba Venda tem quase nada. Isso NÃO é bug de query — é ausência de dado
 * de origem, e some sozinho quando o import entrar. Sem este aviso, o primeiro beta tester
 * a abrir o painel reporta como erro.
 */
const alertaSerieIncompleta = computed(() => {
    const anterior = dados.value?.valoresAnoAnterior ?? [];
    const mesesComDado = anterior.filter((v) => v > 0).length;

    if (mesesComDado === 0) {
        return aba.value === 'venda'
            ? `Ainda não há pedidos de ${dados.value?.anoAnterior} carregados.`
            : `Ainda não há faturamento de ${dados.value?.anoAnterior} carregado.`;
    }

    if (mesesComDado < 6) {
        return `A série de ${dados.value?.anoAnterior} está incompleta (${mesesComDado} de 12 meses com dado).`;
    }

    return null;
});

const meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

const chartData = computed(() => ({
    labels: meses,
    datasets: [
        {
            label: String(dados.value.anoAnterior),
            data: dados.value.valoresAnoAnterior,
            borderColor: '#f59e0b',
            backgroundColor: '#f59e0b',
            borderDash: [6, 4],
            tension: 0.3,
        },
        {
            label: String(dados.value.anoAtual),
            data: dados.value.valoresAnoAtual,
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
    <DarkCard title="Comparação" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>

        <template #actions>
            <div class="flex items-center gap-2">
                <div v-if="abasDisponiveis.length > 1" class="flex overflow-hidden rounded border border-gray-600">
                    <button
                        v-for="opcao in abasDisponiveis"
                        :key="opcao.chave"
                        type="button"
                        class="px-2 py-1 text-xs font-medium transition"
                        :class="aba === opcao.chave ? 'bg-white/20 text-white' : 'text-gray-300 hover:bg-white/10'"
                        @click="aba = opcao.chave"
                    >
                        {{ opcao.rotulo }}
                    </button>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded border border-gray-600 px-2 py-1 text-xs font-medium text-gray-200 transition hover:bg-white/10"
                    @click="expandido = !expandido"
                >
                    {{ expandido ? 'Ocultar evolução ▲' : 'Ver evolução ▾' }}
                </button>
            </div>
        </template>

        <template v-if="expandido && dados">
            <p v-if="alertaSerieIncompleta" class="mb-2 rounded border border-amber/40 bg-amber/10 px-2 py-1 text-xs text-amber-dark">
                {{ alertaSerieIncompleta }}
            </p>
            <div class="h-72">
                <Line :data="chartData" :options="chartOptions" />
            </div>
        </template>
        <p v-else-if="!expandido" class="text-sm text-gray-400">
            Clique em "Ver evolução" para comparar mês a mês.
        </p>
    </DarkCard>
</template>
