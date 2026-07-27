<script setup>
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    pedidosAtencao: {
        type: Object,
        required: true,
    },
});

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <DarkCard title="Pedidos que Requerem Atenção" subtitle="Atrasados e vencendo nos próximos 7 dias">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <polygon points="12,3 21,20 3,20" stroke-linejoin="round" />
                <line x1="12" y1="9" x2="12" y2="14" stroke-linecap="round" />
                <circle cx="12" cy="17" r="0.75" fill="currentColor" stroke="none" />
            </svg>
        </template>

        <div class="flex flex-wrap gap-2">
            <KpiTile :value="pedidosAtencao.atrasados" label="Atrasados" tone="danger" />
            <KpiTile :value="pedidosAtencao.vencendo" label="Vencendo em 7d" tone="warn" />
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
            <KpiTile :value="formatBRL(pedidosAtencao.valorEmRisco)" label="Valor em risco" />
        </div>

        <div class="mt-4 border-t border-gray-100 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Pedidos mais atrasados</p>

            <p v-if="!pedidosAtencao.itens.length" class="mt-3 text-sm text-gray-400">Nenhum pedido atrasado.</p>

            <ul v-else class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1">
                <li v-for="item in pedidosAtencao.itens" :key="item.numero" class="rounded border border-gray-100 p-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold text-gray-800">Pedido {{ item.numero }}</span>
                        <StatusPill tone="danger">{{ item.diasAtraso }} dia(s)</StatusPill>
                    </div>
                    <p class="mt-1 truncate text-sm text-gray-700">{{ item.cliente }}</p>
                    <p class="mt-0.5 text-xs text-gray-400">{{ formatBRL(item.valorTotal) }} · previsão {{ item.previsao }}</p>
                </li>
            </ul>
        </div>
    </DarkCard>
</template>
