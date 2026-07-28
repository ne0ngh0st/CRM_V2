<script setup>
import { computed } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import MetaGaugeRing from '@/Components/Dashboard/MetaGaugeRing.vue';

const props = defineProps({
    metaGauge: {
        type: Object,
        required: true,
    },
    role: { type: String, default: null },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

const termo = computed(() => (props.metaGauge.isRepresentante ? 'Objetivo' : 'Meta'));
const anoAtual = new Date().getFullYear();

const podeVerMetas = computed(() => ['admin', 'diretor', 'supervisor'].includes(props.role));
const hrefMetaMes = computed(() => (podeVerMetas.value
    ? route('metas.index', { visao_supervisor: props.visaoSupervisor || undefined, modo: 'mensal' })
    : null));
const hrefMetaAno = computed(() => (podeVerMetas.value
    ? route('metas.index', { visao_supervisor: props.visaoSupervisor || undefined, modo: 'acumulado' })
    : null));
const hrefPedidosMes = computed(() => route('pedidos.emitidos', {
    visao_supervisor: props.visaoSupervisor || undefined,
    visao_vendedor: props.visaoVendedor || undefined,
}));

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <DarkCard title="Performance Comercial" subtitle="Meta do mês e acumulado do ano">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>

        <div class="flex flex-col items-start justify-center gap-6 sm:flex-row sm:items-start sm:justify-around">
            <MetaGaugeRing label="Mês" :legenda="`${termo} do mês`" :dados="metaGauge.mes" :termo="termo" :href="hrefMetaMes" />
            <MetaGaugeRing label="Ano" :legenda="`Acumulado ${anoAtual}`" :dados="metaGauge.ano" :termo="termo" :href="hrefMetaAno" />
        </div>

        <div v-if="metaGauge.pedidosEmitidos" class="mt-5 border-t border-gray-100 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Pedidos emitidos</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <KpiTile :value="metaGauge.pedidosEmitidos.mes.pedidos" label="Pedidos no mês" :href="hrefPedidosMes" />
                <KpiTile :value="formatBRL(metaGauge.pedidosEmitidos.mes.valor)" label="Valor no mês" tone="info" compact :href="hrefPedidosMes" />
                <KpiTile :value="metaGauge.pedidosEmitidos.ano.pedidos" label="Pedidos no ano" />
                <KpiTile :value="formatBRL(metaGauge.pedidosEmitidos.ano.valor)" label="Valor no ano" tone="info" compact />
            </div>
        </div>
    </DarkCard>
</template>
