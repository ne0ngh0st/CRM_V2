<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import SegmentoChips from '@/Components/Equipe/SegmentoChips.vue';

const props = defineProps({
    carteiraSegmento: {
        type: Object,
        required: true,
    },
    // Filtros já ativos a preservar ao navegar (na Home só visão; na própria
    // Carteira, o objeto `filtros` inteiro da página — busca/estado/segmento/etc).
    baseFiltros: { type: Object, default: () => ({}) },
    /**
     * Segmento(s) de quem está olhando. Vazio quando o escopo não é um vendedor só
     * (equipe/empresa), e aí os chips somem: "o segmento" seriam os 23.
     *
     * ⚠️ Existe porque este card falava em "dentro/fora do segmento" sem jamais dizer
     * aderência a QUÊ — o nome do segmento não aparecia em lugar nenhum da Home.
     * Aqui entram sem prefixo, como contexto dos números logo abaixo; o rótulo
     * "Segmento:" fica só na pill do topo da página, que é identidade.
     */
    segmentos: { type: Array, default: () => [] },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

function carteiraHref(params) {
    return route('carteira.index', {
        visao_supervisor: props.visaoSupervisor || undefined,
        visao_vendedor: props.visaoVendedor || undefined,
        ...props.baseFiltros,
        ...params,
    });
}

const totalAtivos = computed(() => props.carteiraSegmento.dentroSegmento.ativos + props.carteiraSegmento.foraSegmento.ativos);
const totalInativando = computed(() => props.carteiraSegmento.dentroSegmento.inativando + props.carteiraSegmento.foraSegmento.inativando);
const totalInativos = computed(() => props.carteiraSegmento.dentroSegmento.inativos + props.carteiraSegmento.foraSegmento.inativos);

/**
 * Uma linha por status, com a coluna DENTRO e a coluna FORA lado a lado.
 *
 * ⚠️ Antes eram dois painéis empilhados, cada um com título, barra própria e cabeçalho de
 * tabela — 200px a mais de altura para dizer a mesma coisa, e obrigando a comparar
 * "ativos dentro" com "ativos fora" saltando de um bloco para o outro. Lado a lado a
 * comparação é a leitura natural da linha, e o card deixou de esticar a página.
 */
const STATUS = [
    { chave: 'ativo', label: 'Ativos', campo: 'ativos', pct: 'pctAtivos', dot: 'bg-emerald-500' },
    { chave: 'inativando', label: 'Inativando', campo: 'inativando', pct: 'pctInativando', dot: 'bg-amber-500' },
    { chave: 'inativo', label: 'Inativos', campo: 'inativos', pct: 'pctInativos', dot: 'bg-red-500' },
];

const linhas = computed(() => STATUS.map((s) => ({
    ...s,
    dentro: {
        valor: props.carteiraSegmento.dentroSegmento[s.campo],
        pct: props.carteiraSegmento.dentroSegmento[s.pct],
        href: carteiraHref({ status: s.chave, aderencia: 'dentro' }),
    },
    fora: {
        valor: props.carteiraSegmento.foraSegmento[s.campo],
        pct: props.carteiraSegmento.foraSegmento[s.pct],
        href: carteiraHref({ status: s.chave, aderencia: 'fora' }),
    },
})));
</script>

<template>
    <DarkCard
        title="Carteira por Segmento"
        :subtitle="`${carteiraSegmento.total} clientes · ${carteiraSegmento.pctDentro}% no segmento`"
    >
        <template v-if="segmentos.length" #actions>
            <SegmentoChips :segmentos="segmentos" surface="dark" />
        </template>

        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
            </svg>
        </template>

        <!--
            ⚠️ `gap-5` fixo, NÃO `justify-between`: o grid é `items-stretch`, então este
            card recebe a altura do vizinho mais alto. Distribuindo a sobra entre os
            blocos, a barra de aderência e a legenda ficavam boiando no meio do card, com
            uns 65px de vão de cada lado — parecia defeito de layout. Empacotado no topo, a
            sobra fica embaixo, que é onde vão vazio parece intencional.
        -->
        <div v-if="carteiraSegmento.total > 0" class="flex flex-col gap-5">
            <!--
                ⚠️ Sem tile de "% no segmento": esse número já está no subtítulo do card E na
                barra logo abaixo, com mais contexto nos dois. Três vezes a mesma
                porcentagem na mesma caixa era ruído, não reforço.
            -->
            <div class="flex flex-wrap gap-2">
                <KpiTile :value="totalAtivos" label="Ativos" tone="ok" :href="carteiraHref({ status: 'ativo' })" />
                <KpiTile :value="totalInativando" label="Inativando" tone="warn" :href="carteiraHref({ status: 'inativando' })" />
                <KpiTile :value="totalInativos" label="Inativos" tone="danger" :href="carteiraHref({ status: 'inativo' })" />
                <KpiTile
                    v-if="carteiraSegmento.semSegmentoDefinido.total > 0"
                    :value="carteiraSegmento.semSegmentoDefinido.total"
                    label="Sem segmento definido"
                    tone="default"
                    :href="carteiraHref({ aderencia: 'sem_segmento' })"
                />
            </div>

            <div class="space-y-2">
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="shrink-0">
                        <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">No segmento</p>
                        <Link :href="carteiraHref({ aderencia: 'dentro' })" class="tbl-num-link text-lg font-bold text-emerald-600">
                            {{ carteiraSegmento.dentroSegmento.total }}
                            <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctDentro }}%)</span>
                        </Link>
                    </div>
                    <div class="flex h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                        <div class="bg-emerald-500" :style="{ width: carteiraSegmento.pctDentro + '%' }" />
                        <div class="bg-red-400" :style="{ width: carteiraSegmento.pctFora + '%' }" />
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Fora do segmento</p>
                        <Link :href="carteiraHref({ aderencia: 'fora' })" class="tbl-num-link text-lg font-bold text-red-500">
                            {{ carteiraSegmento.foraSegmento.total }}
                            <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctFora }}%)</span>
                        </Link>
                    </div>
                </div>

                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" /> Ativos ≤ 290 dias</span>
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" /> Inativando 291–365 dias</span>
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-red-500" /> Inativos &gt; 365 dias ou sem compra</span>
                </p>
            </div>

            <!--
                ⚠️ Esta tabela é LEGENDA de KPI, não tabela de dados: por isso continua
                fora dos tokens `.tbl*` (alinhamento esquerda/direita, sem divisórias, linha
                inteira clicável). Já está registrado no CLAUDE.md que padronizá-la pioraria
                — não "arrumar" isso depois achando que ficou para trás.
            -->
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[0.65rem] uppercase tracking-wide text-gray-400">
                        <th class="pb-1 text-left font-semibold">Status</th>
                        <th class="pb-1 text-right font-semibold">No segmento</th>
                        <th class="pb-1 text-right font-semibold">Fora do segmento</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="linha in linhas" :key="linha.chave" class="border-t border-gray-100">
                        <td class="py-1.5">
                            <span class="inline-flex items-center gap-1.5 text-gray-700">
                                <span class="h-1.5 w-1.5 rounded-full" :class="linha.dot" />
                                {{ linha.label }}
                            </span>
                        </td>
                        <td class="py-1 text-right">
                            <Link :href="linha.dentro.href" class="tbl-num-link font-semibold text-navy">
                                {{ linha.dentro.valor }}
                                <span class="text-xs font-medium text-gray-400">({{ linha.dentro.pct }}%)</span>
                            </Link>
                        </td>
                        <td class="py-1 text-right">
                            <Link :href="linha.fora.href" class="tbl-num-link font-semibold text-navy">
                                {{ linha.fora.valor }}
                                <span class="text-xs font-medium text-gray-400">({{ linha.fora.pct }}%)</span>
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="text-sm text-gray-400">Nenhum cliente na carteira.</p>
    </DarkCard>
</template>
