<script setup>
/**
 * Resultado de uma cotação: quanto o sistema achou sozinho, quanto falta conferir, e a
 * lista inteira (maior valor primeiro). Rótulo e tom de cada situação vêm do servidor
 * (`CotacaoService::STATUS`) — esta tela não tem mapa próprio.
 */
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';

const props = defineProps({
    cotacao: { type: Object, required: true },
    resumo: { type: Array, required: true },
    pendentes: { type: Object, required: true },
    itens: { type: Object, required: true },
    filtroStatus: { type: String, default: '' },
    statusOpcoes: { type: Array, required: true },
    comCusto: { type: Boolean, default: false },
});

const reais = (v) => Number(v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const numero = (v) => (v == null ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 }));

// KpiTile não tem o tom "neutral" do StatusPill: o "não trabalhamos" fica no default.
const tomDoTile = (tom) => (tom === 'neutral' ? 'default' : tom);

function filtrar(status) {
    router.get(route('cotacoes.show', props.cotacao.id), status ? { status } : {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['itens', 'filtroStatus'],
    });
}

function desfazer(item) {
    router.post(route('cotacoes.itens.desfazer', item.id), {}, { preserveScroll: true });
}

const linhas = computed(() => props.itens.data ?? []);
</script>

<template>
    <Head :title="`Cotação — ${cotacao.arquivo}`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] space-y-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero :title="cotacao.arquivo">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M6 3.6h9l3 3v13.8H6zM9 9.4h6M9 13h3M12.6 16.6l1.8 1.8 3.2-3.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    <span v-if="cotacao.cliente">{{ cotacao.cliente }} · </span>
                    {{ cotacao.itens.toLocaleString('pt-BR') }} itens, {{ reais(cotacao.valor) }} no período ·
                    enviada em {{ cotacao.criadaEm }} · RV de {{ cotacao.estoqueEm ?? '—' }}
                </template>
                <template #meta>
                    <Link :href="route('cotacoes.index')" class="text-xs font-semibold text-gray-300 hover:text-white">← Cotações</Link>
                </template>
            </PageHero>

            <DarkCard title="O que o sistema achou" subtitle="Clique numa situação para filtrar a lista">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M4 19V9M10 19V5M16 19v-7M22 19H2" stroke-linecap="round" />
                    </svg>
                </template>
                <template #actions>
                    <ExportarExcelButton rota="cotacoes.exportar" :filtros="{ cotacao: cotacao.id }" />
                </template>

                <div class="space-y-4 p-4">
                    <ul v-if="cotacao.avisos.length" class="rounded border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-gray-700">
                        <li v-for="(a, i) in cotacao.avisos" :key="i">Leitura da planilha: {{ a }}</li>
                    </ul>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="r in resumo"
                            :key="r.status"
                            type="button"
                            class="flex-1 basis-auto text-left"
                            :class="filtroStatus === r.status ? 'ring-2 ring-navy/40 rounded' : ''"
                            @click="filtrar(filtroStatus === r.status ? '' : r.status)"
                        >
                            <KpiTile :value="r.n.toLocaleString('pt-BR')" :label="`${r.rotulo} · ${reais(r.valor)}`" :tone="tomDoTile(r.tom)" />
                        </button>
                    </div>

                    <div class="flex flex-col gap-3 rounded border border-gray-200 bg-gray-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-gray-700">
                            <template v-if="pendentes.n > 0">
                                <strong>{{ pendentes.n.toLocaleString('pt-BR') }}</strong> itens esperam a sua conferência,
                                valendo <strong>{{ reais(pendentes.valor) }}</strong> no período. Começa pelos de maior valor:
                                dá para parar quando quiser.
                            </template>
                            <template v-else>Nada pendente: tudo já foi conferido ou achado com segurança.</template>
                        </p>
                        <Link
                            v-if="pendentes.n > 0"
                            :href="route('cotacoes.conferir', cotacao.id)"
                            class="shrink-0 rounded bg-navy px-4 py-2 text-center text-sm font-semibold text-white hover:bg-navy/90"
                        >
                            Começar a conferir
                        </Link>
                    </div>
                </div>
            </DarkCard>

            <DarkCard title="Itens da lista" :subtitle="filtroStatus ? statusOpcoes.find((s) => s.valor === filtroStatus)?.rotulo : 'Maior valor no período primeiro'">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" stroke-linecap="round" />
                    </svg>
                </template>
                <template #actions>
                    <button v-if="filtroStatus" type="button" class="text-xs font-semibold text-gray-300 hover:text-white" @click="filtrar('')">
                        Limpar filtro
                    </button>
                </template>

                <div class="tbl-wrap">
                    <table class="tbl tbl-cartoes sm:min-w-[1200px]">
                        <thead>
                            <tr class="tbl-head-row">
                                <th class="tbl-th">Pedido do cliente</th>
                                <th class="tbl-th">Qtd / valor</th>
                                <th class="tbl-th">Situação</th>
                                <th class="tbl-th">Nosso produto</th>
                                <th class="tbl-th">Disponível</th>
                                <th v-if="comCusto" class="tbl-th">Custo</th>
                                <th class="tbl-th">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="tbl-body">
                            <tr v-for="i in linhas" :key="i.id" class="tbl-row">
                                <td class="tbl-td tbl-td-titulo">
                                    <span class="tbl-main sm:max-w-[380px]" :title="i.descricao">{{ i.descricao }}</span>
                                    <span v-if="i.codCliente" class="tbl-sub">cód. {{ i.codCliente }}</span>
                                </td>
                                <td class="tbl-td" data-rotulo="Qtd / valor">
                                    <span class="tbl-main">{{ numero(i.qtd) }} {{ i.unidade }}</span>
                                    <span class="tbl-sub">{{ reais(i.valor) }}</span>
                                </td>
                                <td class="tbl-td" data-rotulo="Situação">
                                    <StatusPill size="sm" :tone="i.statusTom">{{ i.statusRotulo }}</StatusPill>
                                    <span v-if="i.decididoPor" class="tbl-sub">por {{ i.decididoPor }}</span>
                                </td>
                                <td class="tbl-td" data-rotulo="Nosso produto">
                                    <template v-if="i.nosso">
                                        <span class="tbl-main sm:max-w-[380px]" :title="i.nosso.descricao">{{ i.nosso.descricao ?? 'Saiu do estoque de revenda' }}</span>
                                        <span class="tbl-sub">{{ i.nosso.codigo }}<template v-if="i.nosso.marca"> · {{ i.nosso.marca }}</template></span>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="tbl-td" data-rotulo="Disponível">{{ i.nosso ? numero(i.nosso.disponivel) : '—' }}</td>
                                <td v-if="comCusto" class="tbl-td" data-rotulo="Custo">{{ i.nosso?.custo != null ? reais(i.nosso.custo) : '—' }}</td>
                                <td class="tbl-td">
                                    <div class="tbl-acoes">
                                        <button
                                            v-if="i.podeDesfazer"
                                            type="button"
                                            class="tbl-acao tbl-acao-amber"
                                            title="Desfazer a conferência (volta para a fila)"
                                            @click="desfazer(i)"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3">
                    <Pagination :meta="itens" :only="['itens']" />
                </div>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
