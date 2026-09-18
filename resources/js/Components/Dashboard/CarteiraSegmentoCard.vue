<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import SegmentoChips from '@/Components/Equipe/SegmentoChips.vue';
import { ROTULOS_STATUS_CARTEIRA as ROTULO } from '@/constants/carteira';

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
    const query = {
        visao_supervisor: props.visaoSupervisor || undefined,
        visao_vendedor: props.visaoVendedor || undefined,
        ...props.baseFiltros,
        ...params,
    };

    /*
     * ⚠️ Chave com valor vazio é REMOVIDA, e é isso que faz o clique no tile já marcado
     * desfazer o filtro: `baseFiltros` é o objeto `filtros` inteiro da página, então ele
     * traz o `status` atual e um `{ status: '' }` por cima seria ignorado se a chave
     * sobrevivesse até o Ziggy.
     */
    Object.keys(query).forEach((k) => {
        if (query[k] === '' || query[k] === null || query[k] === undefined) {
            delete query[k];
        }
    });

    return route('carteira.index', query);
}

/*
 * O que a TELA está filtrando hoje, para o card marcar em vez de esconder.
 *
 * ⚠️ Sai de `baseFiltros` porque ali já chega o objeto `filtros` da Carteira — na Home
 * ele só tem a visão, então nada fica marcado, que é o correto: lá não há filtro.
 */
const statusAtivo = computed(() => props.baseFiltros?.status || '');
const aderenciaAtiva = computed(() => props.baseFiltros?.aderencia || '');

/** O href de um tile de status: aplica o filtro, ou o remove se já for o aplicado. */
const hrefStatus = (chave) => carteiraHref({ status: statusAtivo.value === chave ? '' : chave });

/**
 * ⚠️ Os três totais somam DENTRO + FORA + SEM SEGMENTO DEFINIDO.
 *
 * Até 2026-09-06 o terceiro balde ficava de fora, e isso produzia dois defeitos:
 *
 *  1. Os quadrinhos não fechavam com o próprio subtítulo do card. No escopo empresa o
 *     card dizia "92.209 clientes" e os tiles somavam 28.746 — os 63.463 clientes de
 *     vendedor sem segmento cadastrado simplesmente não apareciam em lugar nenhum.
 *  2. O card "Segmentos Atendidos", ao lado, mostrava 73.935 inativos contra os 21.619
 *     daqui. Dois números com o mesmo nome, na mesma tela, sem explicação.
 *
 * "Sem segmento definido" continua como tile próprio: ele responde outra pergunta (a
 * ADERÊNCIA não é mensurável para esses clientes), e por isso segue fora do denominador
 * de `pctDentro`/`pctFora`. O que mudou é que eles voltaram a contar no status.
 */
const somaStatus = (campo) => computed(() =>
    props.carteiraSegmento.dentroSegmento[campo]
    + props.carteiraSegmento.foraSegmento[campo]
    + (props.carteiraSegmento.semSegmentoDefinido?.[campo] ?? 0));

const totalAtivos = somaStatus('ativos');
const totalInativando = somaStatus('inativando');
const totalInativos = somaStatus('inativos');

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
    { chave: 'inativando', label: ROTULO.inativando, campo: 'inativando', pct: 'pctInativando', dot: 'bg-amber-500' },
    { chave: 'inativo', label: ROTULO.inativo, campo: 'inativos', pct: 'pctInativos', dot: 'bg-red-500' },
];

/**
 * Uma célula da matriz. Clicar na que já está aplicada desfaz as DUAS dimensões — foi ela
 * que aplicou as duas, então é o que desfaz o que o clique anterior fez.
 */
function celula(chaveStatus, lado, valor, pct) {
    const ativa = statusAtivo.value === chaveStatus && aderenciaAtiva.value === lado;

    return {
        valor,
        pct,
        ativa,
        href: carteiraHref(ativa ? { status: '', aderencia: '' } : { status: chaveStatus, aderencia: lado }),
    };
}

const linhas = computed(() => STATUS.map((s) => ({
    ...s,
    ativa: statusAtivo.value === s.chave,
    dentro: celula(s.chave, 'dentro', props.carteiraSegmento.dentroSegmento[s.campo], props.carteiraSegmento.dentroSegmento[s.pct]),
    fora: celula(s.chave, 'fora', props.carteiraSegmento.foraSegmento[s.campo], props.carteiraSegmento.foraSegmento[s.pct]),
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

                ⚠️ Sem tile de "Sem segmento definido" (removido em 2026-09-06, pedido do
                Tony: "só confunde"). Esses clientes NÃO sumiram da conta — desde a mesma
                data eles entram nos três status acima, que é o que fez os quadrinhos
                fecharem com o total do card e com o quadro "Segmentos Atendidos". O que
                saiu foi só o tile; a aderência deles continua fora do denominador de
                dentro/fora, como sempre foi.
            -->
            <!--
                ⚠️ Os três tiles mostram SEMPRE a carteira inteira, mesmo com `?status=`
                aplicado — o servidor não aplica ao card a faceta que o card desenha (ver
                `CarteiraController::FACETAS_DO_CARD`). Antes, clicar em "Inativos" zerava
                os outros dois e o card passava a responder "quantos inativos entre os
                inativos?". O recorte aplicado aparece MARCADO, nunca como os vizinhos
                zerados.
            -->
            <div class="flex flex-wrap gap-2">
                <KpiTile :value="totalAtivos" label="Ativos" tone="ok" :href="hrefStatus('ativo')" :ativo="statusAtivo === 'ativo'" />
                <KpiTile :value="totalInativando" :label="ROTULO.inativando" tone="warn" :href="hrefStatus('inativando')" :ativo="statusAtivo === 'inativando'" />
                <KpiTile :value="totalInativos" :label="ROTULO.inativo" tone="danger" :href="hrefStatus('inativo')" :ativo="statusAtivo === 'inativo'" />
            </div>

            <div class="space-y-2">
                <!--
                    ⚠️ No celular a BARRA desce para uma linha própria (`order-last w-full`),
                    e os dois números dividem a linha de cima. Até 2026-09-15 os três eram
                    irmãos de um `flex` só, com a barra em `flex-1` entre dois `shrink-0` —
                    a 320px os dois blocos laterais tomavam a largura inteira e a barra
                    colapsava num toco de ~30px, que na tela parecia um interruptor ligado,
                    não um gráfico. Mesma armadilha documentada no `PageHero`: quando os
                    dois lados são `shrink-0`, quem tem `flex-1` é a vítima.
                -->
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 sm:flex-nowrap sm:gap-4">
                    <div class="shrink-0">
                        <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">No segmento</p>
                        <Link
                            :href="carteiraHref({ aderencia: aderenciaAtiva === 'dentro' ? '' : 'dentro' })"
                            class="tbl-num-link text-lg font-bold text-emerald-600"
                            :class="aderenciaAtiva === 'dentro' ? 'rounded bg-emerald-50 px-1.5 ring-1 ring-emerald-500' : ''"
                            :title="aderenciaAtiva === 'dentro' ? 'Filtro aplicado — clique para remover' : undefined"
                        >
                            {{ carteiraSegmento.dentroSegmento.total }}
                            <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctDentro }}%)</span>
                        </Link>
                    </div>
                    <div
                        class="order-last flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100 sm:order-none sm:w-auto sm:flex-1"
                    >
                        <div class="bg-emerald-500" :style="{ width: carteiraSegmento.pctDentro + '%' }" />
                        <div class="bg-red-400" :style="{ width: carteiraSegmento.pctFora + '%' }" />
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Fora do segmento</p>
                        <Link
                            :href="carteiraHref({ aderencia: aderenciaAtiva === 'fora' ? '' : 'fora' })"
                            class="tbl-num-link text-lg font-bold text-red-500"
                            :class="aderenciaAtiva === 'fora' ? 'rounded bg-red-50 px-1.5 ring-1 ring-red-400' : ''"
                            :title="aderenciaAtiva === 'fora' ? 'Filtro aplicado — clique para remover' : undefined"
                        >
                            {{ carteiraSegmento.foraSegmento.total }}
                            <span class="text-xs font-medium text-gray-400">({{ carteiraSegmento.pctFora }}%)</span>
                        </Link>
                    </div>
                </div>

                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400">
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500" /> Ativos ≤ 290 dias</span>
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-amber-500" /> {{ ROTULO.inativando }} 291–365 dias</span>
                    <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-red-500" /> {{ ROTULO.inativo }} &gt; 365 dias ou sem compra</span>
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
                        <th class="pb-1 text-left font-semibold" title="Só os clientes com aderência mensurável — quem não tem segmento cadastrado fica no tile ao lado">Status</th>
                        <th class="pb-1 text-right font-semibold">No segmento</th>
                        <th class="pb-1 text-right font-semibold">Fora do segmento</th>
                    </tr>
                </thead>
                <tbody>
                    <!--
                        A linha do status aplicado fica realçada — é a mesma informação do
                        tile marcado, repetida onde o olho está quando se lê a matriz. Só
                        fundo e peso de fonte: cor nova aqui competiria com os três dots,
                        que já carregam significado.
                    -->
                    <tr
                        v-for="linha in linhas"
                        :key="linha.chave"
                        class="border-t border-gray-100"
                        :class="linha.ativa ? 'bg-gray-50' : ''"
                    >
                        <td class="py-1.5">
                            <span class="inline-flex items-center gap-1.5" :class="linha.ativa ? 'font-semibold text-gray-900' : 'text-gray-700'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="linha.dot" />
                                {{ linha.label }}
                            </span>
                        </td>
                        <td class="py-1 text-right">
                            <Link
                                :href="linha.dentro.href"
                                class="tbl-num-link font-semibold text-navy"
                                :class="linha.dentro.ativa ? 'rounded bg-white px-1.5 ring-1 ring-navy' : ''"
                                :title="linha.dentro.ativa ? 'Filtro aplicado — clique para remover' : undefined"
                            >
                                {{ linha.dentro.valor }}
                                <span class="text-xs font-medium text-gray-400">({{ linha.dentro.pct }}%)</span>
                            </Link>
                        </td>
                        <td class="py-1 text-right">
                            <Link
                                :href="linha.fora.href"
                                class="tbl-num-link font-semibold text-navy"
                                :class="linha.fora.ativa ? 'rounded bg-white px-1.5 ring-1 ring-navy' : ''"
                                :title="linha.fora.ativa ? 'Filtro aplicado — clique para remover' : undefined"
                            >
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
