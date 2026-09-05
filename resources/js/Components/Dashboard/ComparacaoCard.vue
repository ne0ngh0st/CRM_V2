<script setup>
/**
 * Comparação ano vs. ano, mês a mês, com duas abas: VENDA e FATURAMENTO.
 *
 * ⚠️ É TABELA, não gráfico, e a troca foi pedida pela direção (2026-09-05) — não é
 * preferência de implementação. O Chart.js saiu do projeto junto: este era o único
 * arquivo que o importava.
 *
 * ⚠️ MESES EM COLUNAS, séries em linhas. Com meses em linhas seriam 14 linhas altas e
 * duas faixas vazias nas laterais numa tela de 1800px; em colunas o card ocupa a largura
 * que tem, cabe em 3 linhas, e o ACUMULADO fecha a leitura à direita — que é o número que
 * substituiu o "acumulado do ano" do card de Performance Comercial ao lado.
 *
 * ⚠️ VENDA é a aba padrão. Venda é pedido emitido — o que o vendedor consegue influenciar
 * hoje; faturamento é consequência, e chega depois.
 *
 * ⚠️ As duas séries vêm juntas do servidor, e o alternador NÃO vai ao servidor. Cada aba
 * custa uma agregação cacheada; buscá-la no clique tornaria a troca lenta justamente para
 * quem alterna.
 *
 * ⚠️ O período das duas abas é o MESMO (D-1 no mês corrente, D-3 na segunda). Duas
 * convenções de período na mesma tela produziriam dois números para o mesmo mês.
 */
import DarkCard from '@/Components/DarkCard.vue';
import { computed, ref } from 'vue';

const props = defineProps({
    vendaComparacao: { type: Object, default: null },
    faturamentoComparacao: { type: Object, default: null },
});

const ABAS = [
    { chave: 'venda', rotulo: 'Venda', subtitulo: 'Pedidos emitidos · ano atual vs. anterior' },
    { chave: 'faturamento', rotulo: 'Faturamento', subtitulo: 'Notas emitidas · ano atual vs. anterior' },
];

const aba = ref(props.vendaComparacao ? 'venda' : 'faturamento');

const abasDisponiveis = computed(() =>
    ABAS.filter((a) => (a.chave === 'venda' ? props.vendaComparacao : props.faturamentoComparacao)),
);

const dados = computed(() =>
    aba.value === 'venda' ? props.vendaComparacao : props.faturamentoComparacao,
);

const subtitulo = computed(() => ABAS.find((a) => a.chave === aba.value)?.subtitulo ?? '');

const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

/**
 * Até que mês do ano corrente existe período. Depois dele a célula fica EM BRANCO, nunca
 * "R$ 0,00": num relatório mensal, zero significa "não vendeu" e é uma afirmação sobre o
 * desempenho — dezembro não vendeu nada porque dezembro não chegou.
 *
 * Lido do relógio do navegador, como o resto desta página já faz (`mesAno` no Dashboard,
 * `anoAtual` no MetaGaugeCard). O servidor garante o mesmo corte: `somaMensal()` limita o
 * intervalo ao mês corrente, então mês futuro chega zerado de qualquer forma — isto aqui é
 * a diferença entre exibir o zero e não exibir.
 */
const mesLimite = computed(() => {
    const agora = new Date();
    return dados.value?.anoAtual === agora.getFullYear() ? agora.getMonth() + 1 : 12;
});

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const compacto = new Intl.NumberFormat('pt-BR', {
    notation: 'compact',
    compactDisplay: 'short',
    maximumFractionDigits: 1,
});

const soma = (valores) => (valores ?? []).reduce((total, v) => total + (v || 0), 0);

/**
 * Uma célula já resolvida: o que exibir, o que pôr no `title` e se é período futuro.
 * Concentrado aqui para que as três linhas da tabela não repitam a regra de exibição.
 */
function celula(valor, mes) {
    if (mes !== null && mes > mesLimite.value) {
        return { texto: '', title: 'Mês ainda não iniciado', futuro: true };
    }

    if (!valor) {
        return { texto: '—', title: 'Sem movimento no período', futuro: false };
    }

    return { texto: compacto.format(valor), title: brl.format(valor), futuro: false };
}

/** Variação percentual. Sem base no ano anterior não há variação — nunca "+100%". */
function variacao(atual, anterior, mes) {
    if (mes !== null && mes > mesLimite.value) {
        return { texto: '', title: 'Mês ainda não iniciado', futuro: true, tom: '' };
    }

    if (!anterior) {
        return { texto: '—', title: 'Sem base no ano anterior', futuro: false, tom: '' };
    }

    const pct = ((atual - anterior) / anterior) * 100;
    const sinal = pct > 0 ? '+' : '';

    return {
        texto: `${sinal}${pct.toFixed(0)}%`,
        title: `${sinal}${pct.toFixed(1)}% vs. ${brl.format(anterior)}`,
        futuro: false,
        tom: pct >= 0 ? 'text-emerald-600' : 'text-red-500',
    };
}

const linhaAtual = computed(() =>
    MESES.map((_, i) => celula(dados.value.valoresAnoAtual[i], i + 1)),
);

const linhaAnterior = computed(() =>
    // Ano fechado: todo mês teve período, então nada aqui é "futuro".
    MESES.map((_, i) => celula(dados.value.valoresAnoAnterior[i], null)),
);

const linhaVariacao = computed(() =>
    MESES.map((_, i) => variacao(dados.value.valoresAnoAtual[i], dados.value.valoresAnoAnterior[i], i + 1)),
);

const acumuladoAtual = computed(() => soma(dados.value.valoresAnoAtual));
const acumuladoAnterior = computed(() => soma(dados.value.valoresAnoAnterior));
const acumuladoVariacao = computed(() =>
    variacao(acumuladoAtual.value, acumuladoAnterior.value, null),
);

/**
 * ⚠️ Declara quando a série do ano anterior está incompleta, em vez de deixar uma linha
 * inteira de travessões parecer defeito.
 *
 * O histórico de pedidos emitidos ainda não foi carregado (existe no legado), então hoje o
 * ano anterior da aba Venda tem quase nada. Não é bug de query — é ausência de dado de
 * origem, e some sozinho quando o import entrar.
 */
const alertaSerieIncompleta = computed(() => {
    const anterior = dados.value?.valoresAnoAnterior ?? [];
    const mesesComDado = anterior.filter((v) => v > 0).length;

    if (mesesComDado === 0) {
        return aba.value === 'venda'
            ? `Ainda não há pedidos de ${dados.value?.anoAnterior} carregados — a linha do ano anterior fica vazia.`
            : `Ainda não há faturamento de ${dados.value?.anoAnterior} carregado — a linha do ano anterior fica vazia.`;
    }

    if (mesesComDado < 6) {
        return `A série de ${dados.value?.anoAnterior} está incompleta (${mesesComDado} de 12 meses com dado).`;
    }

    return null;
});
</script>

<template>
    <DarkCard title="Comparação" :subtitle="subtitulo">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 5h16M4 12h16M4 19h16" stroke-linecap="round" />
                <path d="M9 5v14" stroke-linecap="round" />
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

        <template v-if="dados">
            <p v-if="alertaSerieIncompleta" class="mb-3 rounded border border-amber/40 bg-amber/10 px-2 py-1 text-xs text-amber-dark">
                {{ alertaSerieIncompleta }}
            </p>

            <div class="tbl-wrap">
                <table class="tbl min-w-[1000px]">
                    <thead>
                        <tr class="tbl-head-row">
                            <th class="tbl-th text-left">Período</th>
                            <th
                                v-for="(mes, i) in MESES"
                                :key="mes"
                                class="tbl-th"
                                :class="i + 1 > mesLimite ? 'text-gray-300' : ''"
                            >
                                {{ mes }}<span v-if="i + 1 === mesLimite" title="Mês corrente, até D-1">*</span>
                            </th>
                            <th class="tbl-th border-l-2 border-gray-300 bg-gray-100 text-gray-700">Acumulado</th>
                        </tr>
                    </thead>
                    <tbody class="tbl-body">
                        <tr class="tbl-row">
                            <td class="tbl-td text-left font-semibold text-gray-800">{{ dados.anoAtual }}</td>
                            <td
                                v-for="(c, i) in linhaAtual"
                                :key="i"
                                class="tbl-td font-medium text-gray-800"
                                :class="c.futuro ? 'bg-gray-50' : ''"
                                :title="c.title"
                            >
                                {{ c.texto }}
                            </td>
                            <td class="tbl-td border-l-2 border-gray-300 bg-gray-50 font-bold text-gray-900" :title="brl.format(acumuladoAtual)">
                                {{ compacto.format(acumuladoAtual) }}
                            </td>
                        </tr>

                        <tr class="tbl-row">
                            <td class="tbl-td text-left text-gray-500">{{ dados.anoAnterior }}</td>
                            <td
                                v-for="(c, i) in linhaAnterior"
                                :key="i"
                                class="tbl-td text-gray-500"
                                :title="c.title"
                            >
                                {{ c.texto }}
                            </td>
                            <td class="tbl-td border-l-2 border-gray-300 bg-gray-50 font-semibold text-gray-600" :title="brl.format(acumuladoAnterior)">
                                {{ acumuladoAnterior ? compacto.format(acumuladoAnterior) : '—' }}
                            </td>
                        </tr>

                        <tr class="tbl-row">
                            <td class="tbl-td text-left text-[0.7rem] uppercase tracking-wide text-gray-400">Variação</td>
                            <td
                                v-for="(c, i) in linhaVariacao"
                                :key="i"
                                class="tbl-td text-xs font-medium"
                                :class="[c.tom || 'text-gray-400', c.futuro ? 'bg-gray-50' : '']"
                                :title="c.title"
                            >
                                {{ c.texto }}
                            </td>
                            <td
                                class="tbl-td border-l-2 border-gray-300 bg-gray-50 text-xs font-bold"
                                :class="acumuladoVariacao.tom || 'text-gray-400'"
                                :title="acumuladoVariacao.title"
                            >
                                {{ acumuladoVariacao.texto }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-2 text-[0.7rem] text-gray-400">
                Valores abreviados; passe o mouse para o valor exato.
                <span v-if="dados.anoAtual === new Date().getFullYear()">
                    O mês corrente (*) vai até o dia anterior (D-1).
                </span>
            </p>
        </template>
    </DarkCard>
</template>
