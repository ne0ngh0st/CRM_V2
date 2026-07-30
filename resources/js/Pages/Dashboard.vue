<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import StatusPill from '@/Components/StatusPill.vue';
import VisaoSelector from '@/Components/Dashboard/VisaoSelector.vue';
import MetaGaugeCard from '@/Components/Dashboard/MetaGaugeCard.vue';
import CarteiraSegmentoCard from '@/Components/Dashboard/CarteiraSegmentoCard.vue';
import LigacoesStatsCards from '@/Components/Dashboard/LigacoesStatsCards.vue';
import OrcamentosStatsCard from '@/Components/Dashboard/OrcamentosStatsCard.vue';
import PedidosAtencaoCard from '@/Components/Dashboard/PedidosAtencaoCard.vue';
import FaturamentoComparisonChart from '@/Components/Dashboard/FaturamentoComparisonChart.vue';
import SugestoesBoard from '@/Components/Dashboard/SugestoesBoard.vue';
import { Head, usePage } from '@inertiajs/vue3';

const props = defineProps({
    role: String,
    statusSistema: Array,
    visao: Object,
    metaGauge: Object,
    ligacoesStats: Object,
    observacoesStats: Object,
    faturamentoComparacao: Object,
    carteiraSegmento: Object,
    orcamentosStats: Object,
    pedidosAtencao: Object,
});

const page = usePage();

const tonsStatus = {
    atualizado: 'ok',
    atencao: 'warn',
    desatualizado: 'danger',
};

const labelsStatus = {
    atualizado: 'Atualizado',
    atencao: 'Atenção',
    desatualizado: 'Desatualizado',
};

// Uma métrica só pro sistema como um todo — o pior status entre as tabelas
// sincronizadas, não uma pill por tabela.
const prioridadeStatus = { desatualizado: 3, atencao: 2, atualizado: 1 };

const statusGeral = computed(() => {
    if (!props.statusSistema.length) return null;

    const pior = props.statusSistema.reduce((acc, item) =>
        (prioridadeStatus[item.status] || 0) > (prioridadeStatus[acc.status] || 0) ? item : acc,
    );

    return {
        tom: tonsStatus[pior.status] || 'neutral',
        label: labelsStatus[pior.status] || 'Verificando…',
    };
});

const mesAno = computed(() => {
    const meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const agora = new Date();
    return `${meses[agora.getMonth()]} ${agora.getFullYear()}`;
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Painel Comercial">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 20V4" stroke-linecap="round" />
                            <path d="M4 20h16" stroke-linecap="round" />
                            <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Olá, <strong class="font-semibold text-gray-100">{{ page.props.auth.user.display_name || page.props.auth.user.name }}</strong>
                        · {{ mesAno }} · dados referentes ao dia anterior (D-1)
                    </template>
                    <template #meta>
                        <StatusPill v-if="statusGeral" :tone="statusGeral.tom" surface="dark">
                            Sistema: {{ statusGeral.label }}
                        </StatusPill>
                    </template>
                    <template v-if="visao.mostrarSeletor" #filtros>
                        <VisaoSelector :visao="visao" />
                    </template>
                </PageHero>

                <FaturamentoComparisonChart v-if="faturamentoComparacao" :faturamento-comparacao="faturamentoComparacao" />

                <div v-if="carteiraSegmento || metaGauge" class="grid gap-4 lg:grid-cols-2 lg:items-stretch">
                    <CarteiraSegmentoCard
                        v-if="carteiraSegmento"
                        :carteira-segmento="carteiraSegmento"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <MetaGaugeCard
                        v-if="metaGauge"
                        :meta-gauge="metaGauge"
                        :role="role"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                </div>

                <div v-if="ligacoesStats || orcamentosStats || pedidosAtencao" class="grid gap-4 lg:grid-cols-3 lg:items-stretch">
                    <LigacoesStatsCards
                        v-if="ligacoesStats"
                        :ligacoes-stats="ligacoesStats"
                        :observacoes-stats="observacoesStats"
                        :role="role"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <OrcamentosStatsCard
                        v-if="orcamentosStats"
                        :orcamentos-stats="orcamentosStats"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                    <PedidosAtencaoCard
                        v-if="pedidosAtencao"
                        :pedidos-atencao="pedidosAtencao"
                        :visao-supervisor="visao.visaoSupervisor"
                        :visao-vendedor="visao.visaoVendedor"
                    />
                </div>

                <SugestoesBoard :role="role" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
