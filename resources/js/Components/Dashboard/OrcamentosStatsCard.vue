<script setup>
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    orcamentosStats: {
        type: Object,
        required: true,
    },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

function orcamentosHref(params) {
    return route('orcamentos.index', {
        visao_supervisor: props.visaoSupervisor || undefined,
        visao_vendedor: props.visaoVendedor || undefined,
        ...params,
    });
}

const statusTom = {
    aprovado: 'ok',
    rejeitado: 'danger',
    pendente: 'warn',
};

const statusLabel = {
    aprovado: 'Aprovado',
    rejeitado: 'Rejeitado',
    pendente: 'Pendente',
};

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <DarkCard title="Estatísticas de Orçamentos" subtitle="Resumo geral e do mês atual">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <rect x="6" y="3" width="12" height="18" rx="1" />
                <line x1="9" y1="8" x2="15" y2="8" stroke-linecap="round" />
                <line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" />
                <line x1="9" y1="16" x2="13" y2="16" stroke-linecap="round" />
            </svg>
        </template>

        <div class="flex flex-wrap gap-2">
            <KpiTile :value="orcamentosStats.total" label="Total" :href="orcamentosHref({})" />
            <KpiTile :value="orcamentosStats.aguardandoSupervisor" label="Aguard. supervisor" tone="warn" :href="orcamentosHref({ status: 'pendente', nivel: 'supervisor' })" />
            <KpiTile :value="orcamentosStats.aguardandoDiretor" label="Aguard. diretor" tone="warn" :href="orcamentosHref({ status: 'pendente', nivel: 'diretor' })" />
            <KpiTile :value="orcamentosStats.aprovados" label="Aprovados" tone="ok" :href="orcamentosHref({ status: 'aprovado' })" />
            <KpiTile :value="orcamentosStats.rejeitados" label="Rejeitados" tone="danger" :href="orcamentosHref({ status: 'rejeitado' })" />
            <KpiTile :value="formatBRL(orcamentosStats.valorAprovado)" label="Valor aprovado" tone="info" compact :href="orcamentosHref({ status: 'aprovado' })" />
        </div>

        <div class="mt-4 border-t border-gray-100 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Últimos orçamentos</p>

            <p v-if="!orcamentosStats.itens.length" class="mt-3 text-sm text-gray-400">Nenhum orçamento registrado.</p>

            <ul v-else class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1">
                <li v-for="item in orcamentosStats.itens" :key="item.id">
                    <Link
                        :href="route('orcamentos.editar', item.id)"
                        class="block rounded border border-gray-100 p-2.5 transition hover:border-navy hover:bg-gray-50"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold text-gray-800">Orçamento #{{ item.id }}</span>
                            <StatusPill :tone="statusTom[item.status] || 'neutral'">{{ statusLabel[item.status] || item.status }}</StatusPill>
                        </div>
                        <p class="mt-1 truncate text-sm text-gray-700">{{ item.cliente }}</p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            {{ formatBRL(item.valorTotal) }} · {{ item.criadoHoje ? 'Criado hoje' : `Criado em ${item.criadoEm}` }}
                        </p>
                    </Link>
                </li>
            </ul>
        </div>
    </DarkCard>
</template>
