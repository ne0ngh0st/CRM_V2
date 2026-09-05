<script setup>
/**
 * Performance comercial: meta × realizado do MÊS.
 *
 * ⚠️ DUAS ABAS — VENDA e FATURAMENTO — com VENDA como padrão. Até 2026-09-04 o gauge só
 * media faturamento, e faturamento é consequência: mede a nota, que sai dias depois do
 * pedido. Venda (pedido emitido) é o que o vendedor consegue mover hoje. Mesma decisão, e
 * de propósito o mesmo alternador visual, do card de Comparação logo acima.
 *
 * ⚠️ Os dois tipos vêm juntos do servidor e a troca de aba NÃO vai ao servidor. Ver o
 * docblock de DashboardBlocos::metaGauge().
 *
 * ⚠️ Os tiles de "Pedidos emitidos" repetem o realizado da aba Venda de propósito, e a
 * igualdade é GARANTIDA, não coincidência: back-end e tiles somam `pedidos.valor_total` na
 * mesma janela e sobre o mesmo universo de códigos. Se um dia esses dois números
 * divergirem na tela, o bug está no escopo do servidor, não aqui.
 *
 * ⚠️ O REALIZADO DO ANO saiu daqui em 2026-09-05, quando o card de Comparação virou tabela
 * e passou a fechar com um subtotal acumulado. Saíram a linha "acumulado <ano>" e o tile
 * "Valor no ano", que eram o mesmo número a poucos centímetros. FICARAM, de propósito:
 *   · o tile "Pedidos no ano", porque é CONTAGEM de pedidos, e a tabela só fala em R$.
 *
 * ⚠️ O ANEL DO ANO também saiu, em revisão no mesmo dia. Ele era a única leitura de "estou
 * dentro do objetivo do ANO?" que restava na Home, e a troca é consciente: as metas anuais
 * em produção hoje são resto de seed (só ago-dez preenchidos, valores na escala de
 * *quantidade* de pedidos), então o anel marcava percentuais de três dígitos e ensinava o
 * vendedor a ignorar o card. Um número errado em destaque é pior que número nenhum. Quando
 * as metas forem cadastradas de verdade em /metas, vale reavaliar — o payload continua
 * entregando `venda.ano`/`faturamento.ano`, então é só voltar a renderizar.
 */
import { computed, ref } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import MetaGaugeRing from '@/Components/Dashboard/MetaGaugeRing.vue';
import MetaRealizadoCard from '@/Components/Dashboard/MetaRealizadoCard.vue';

const props = defineProps({
    metaGauge: {
        type: Object,
        required: true,
    },
    role: { type: String, default: null },
    visaoSupervisor: { type: String, default: null },
    visaoVendedor: { type: String, default: null },
});

const ABAS = [
    { chave: 'venda', rotulo: 'Venda', nome: 'Venda', subtitulo: 'Pedidos emitidos · meta do mês' },
    { chave: 'faturamento', rotulo: 'Faturamento', nome: 'Faturamento', subtitulo: 'Notas emitidas · meta do mês' },
];

const aba = ref('venda');

const abasDisponiveis = computed(() => ABAS.filter((a) => props.metaGauge[a.chave]));

const dados = computed(() => props.metaGauge[aba.value] ?? props.metaGauge.faturamento);
const abaAtual = computed(() => ABAS.find((a) => a.chave === aba.value) ?? ABAS[0]);

const termo = computed(() => (props.metaGauge.isRepresentante ? 'Objetivo' : 'Meta'));

const podeVerMetas = computed(() => ['admin', 'diretor', 'supervisor'].includes(props.role));
const hrefMetaMes = computed(() => (podeVerMetas.value
    ? route('metas.index', { visao_supervisor: props.visaoSupervisor || undefined, modo: 'mensal' })
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
    <DarkCard title="Performance Comercial" :subtitle="abaAtual.subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <polyline points="7,16 11,11 14,13 19,7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>

        <template #actions>
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
        </template>

        <!--
            ⚠️ Anel do MÊS e o detalhe meta × realizado LADO A LADO, não empilhados. Eram
            dois anéis (mês e ano) numa fileira e o detalhe embaixo; com o anel do ano fora,
            sobrava metade da fileira vazia e o card continuava alto do mesmo jeito — anéis
            lado a lado não encolhem ao remover um. Assim o card perde ~120px e passa a
            fechar na altura da Carteira por Segmento ao lado.
        -->
        <!--
            ⚠️ `flex-1` no corpo e `justify-between` distribuindo os três blocos: este card
            divide a fileira com a Carteira por Segmento e recebe a altura do mais alto.
            Sem isso sobravam ~50px mortos no pé, e o card parecia ter acabado antes da
            borda. Aqui a sobra vira respiro entre o anel, os tiles e a base.
        -->
        <div class="flex flex-1 flex-col justify-between gap-4">
            <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
                <MetaGaugeRing label="Mês" :legenda="`${termo} do mês`" :dados="dados.mes" :href="hrefMetaMes" />
                <div class="w-full flex-1">
                    <MetaRealizadoCard
                        :titulo="`${abaAtual.nome} do mês`"
                        :dados="dados.mes"
                        :termo="termo"
                        :href="hrefMetaMes"
                    />
                </div>
            </div>

            <div v-if="metaGauge.pedidosEmitidos" class="border-t border-gray-100 pt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Pedidos emitidos</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <KpiTile :value="metaGauge.pedidosEmitidos.mes.pedidos" label="Pedidos no mês" :href="hrefPedidosMes" />
                    <KpiTile :value="formatBRL(metaGauge.pedidosEmitidos.mes.valor)" label="Valor no mês" tone="info" compact :href="hrefPedidosMes" />
                    <KpiTile :value="metaGauge.pedidosEmitidos.ano.pedidos" label="Pedidos no ano" />
                </div>
            </div>
        </div>
    </DarkCard>
</template>
