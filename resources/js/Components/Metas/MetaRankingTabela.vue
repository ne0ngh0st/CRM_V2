<script setup>
/**
 * Tabela do ranking de metas — usada para a lista plana e para cada equipe.
 *
 * ⚠️ É componente, e não linhas de cabeçalho dentro de UMA tabela, por causa do celular:
 * o reflow de `.tbl-cartoes` (app.css) transforma todo `tr` do `tbody` em cartão, e um
 * `<tr>` de título de equipe viraria um cartão fantasma. Mesmo desenho de
 * Components/Equipe/UsuariosGrupo.vue: uma tabela por grupo, título fora dela.
 *
 * `aba` escolhe o bloco de colunas (faturamento ou venda). A troca é só de apresentação:
 * as duas famílias de número já vêm na linha.
 *
 * "Falta vender" = meta de faturamento − faturado − carteira em aberto que ainda fatura no
 * mês. A regra e os nulos são decididos no servidor (MetaRankingResolver::ranking); aqui só
 * se escolhe como escrever cada caso:
 *   · positiva → texto em negrito, sem pill: quase toda linha tem falta, e pintar tudo de
 *     vermelho faz a cor deixar de significar alguma coisa;
 *   · zero ou negativa → pill verde "Coberto", com o excedente no tooltip;
 *   · nula (sem meta, ou mês já fechado) → travessão.
 */
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    linhas: { type: Array, required: true },
    totais: { type: Object, required: true },
    aba: { type: String, default: 'faturamento' },
    podeEditar: { type: Boolean, default: false },
    rotuloTotais: { type: String, default: 'Totais' },
});

const emit = defineEmits(['editar']);

function formatMoney(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatPct(v) {
    return `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
}

function tonePct(v) {
    if (v >= 100) return 'ok';
    if (v >= 80) return 'warn';
    return 'danger';
}

function tituloCoberto(falta) {
    return falta < 0
        ? `A carteira em aberto já passa ${formatMoney(-falta)} da meta`
        : 'A carteira em aberto cobre exatamente a meta';
}
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl tbl-cartoes sm:min-w-[760px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Vendedor</th>
                    <template v-if="aba === 'faturamento'">
                        <th class="tbl-th">Faturado</th>
                        <th class="tbl-th">Meta</th>
                        <th class="tbl-th">%</th>
                        <th class="tbl-th" title="Pedidos em aberto com previsão de faturamento até o fim do mês, emitidos nos últimos 180 dias">
                            Em aberto (hoje)
                        </th>
                        <th class="tbl-th" title="Meta − faturado − em aberto: quanto ainda falta colocar de pedido">
                            Falta vender
                        </th>
                    </template>
                    <template v-else>
                        <th class="tbl-th">Vendido</th>
                        <th class="tbl-th">Meta</th>
                        <th class="tbl-th">%</th>
                    </template>
                    <th v-if="podeEditar" class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="linha in linhas" :key="linha.userId" class="tbl-row">
                    <!-- Código dobrado sob o nome: a busca casa por ele, então ele fica visível. -->
                    <td class="tbl-td tbl-td-titulo">
                        <span class="tbl-main text-gray-800">{{ linha.nome }}</span>
                        <span class="tbl-sub">{{ linha.codVendedor }}</span>
                    </td>

                    <template v-if="aba === 'faturamento'">
                        <td class="tbl-td" data-rotulo="Faturado">{{ formatMoney(linha.fatRealizado) }}</td>
                        <td class="tbl-td" data-rotulo="Meta">{{ formatMoney(linha.fatMeta) }}</td>
                        <td class="tbl-td" data-rotulo="%">
                            <StatusPill v-if="linha.fatPct !== null" :tone="tonePct(linha.fatPct)" size="sm">{{ formatPct(linha.fatPct) }}</StatusPill>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Em aberto">
                            <template v-if="linha.emAberto !== null">{{ formatMoney(linha.emAberto) }}</template>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Falta vender">
                            <span v-if="linha.faltaVender === null" class="text-gray-400">—</span>
                            <StatusPill v-else-if="linha.faltaVender <= 0" tone="ok" size="sm" :title="tituloCoberto(linha.faltaVender)">Coberto</StatusPill>
                            <strong v-else class="font-semibold text-gray-800">{{ formatMoney(linha.faltaVender) }}</strong>
                        </td>
                    </template>
                    <template v-else>
                        <td class="tbl-td" data-rotulo="Vendido">{{ formatMoney(linha.vendaRealizado) }}</td>
                        <td class="tbl-td" data-rotulo="Meta">{{ formatMoney(linha.vendaMeta) }}</td>
                        <td class="tbl-td" data-rotulo="%">
                            <StatusPill v-if="linha.vendaPct !== null" :tone="tonePct(linha.vendaPct)" size="sm">{{ formatPct(linha.vendaPct) }}</StatusPill>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                    </template>

                    <!-- Nas duas abas: o modal edita as duas metas de uma vez. -->
                    <td v-if="podeEditar" class="tbl-td tbl-td-acoes">
                        <div class="tbl-acoes">
                            <button
                                type="button"
                                title="Editar metas do mês"
                                class="tbl-acao tbl-acao-teal"
                                @click="emit('editar', linha)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 20h4l10-10-4-4L4 16v4z" stroke-linejoin="round" />
                                    <path d="M13 7l4 4" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <!-- Linha de totais: só esta tabela tem, por isso não virou token.
                     O `tfoot` entra no mesmo reflow de cartão (app.css). -->
                <tr class="divide-x divide-gray-200 border-t-2 border-gray-300 bg-gray-50 font-semibold text-gray-800">
                    <td class="tbl-td tbl-td-titulo text-gray-800">{{ rotuloTotais }}</td>

                    <template v-if="aba === 'faturamento'">
                        <td class="tbl-td text-gray-800" data-rotulo="Faturado">{{ formatMoney(totais.fatRealizado) }}</td>
                        <td class="tbl-td text-gray-800" data-rotulo="Meta">{{ formatMoney(totais.fatMeta) }}</td>
                        <td class="tbl-td" data-rotulo="%">
                            <StatusPill v-if="totais.fatPct !== null" :tone="tonePct(totais.fatPct)" size="sm">{{ formatPct(totais.fatPct) }}</StatusPill>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td text-gray-800" data-rotulo="Em aberto">
                            <template v-if="totais.emAberto !== null">{{ formatMoney(totais.emAberto) }}</template>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td text-gray-800" data-rotulo="Falta vender">
                            <span v-if="totais.faltaVender === null" class="text-gray-400">—</span>
                            <StatusPill v-else-if="totais.faltaVender <= 0" tone="ok" size="sm" :title="tituloCoberto(totais.faltaVender)">Coberto</StatusPill>
                            <template v-else>{{ formatMoney(totais.faltaVender) }}</template>
                        </td>
                    </template>
                    <template v-else>
                        <td class="tbl-td text-gray-800" data-rotulo="Vendido">{{ formatMoney(totais.vendaRealizado) }}</td>
                        <td class="tbl-td text-gray-800" data-rotulo="Meta">{{ formatMoney(totais.vendaMeta) }}</td>
                        <td class="tbl-td" data-rotulo="%">
                            <StatusPill v-if="totais.vendaPct !== null" :tone="tonePct(totais.vendaPct)" size="sm">{{ formatPct(totais.vendaPct) }}</StatusPill>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                    </template>

                    <td v-if="podeEditar" class="tbl-td tbl-td-oculto" />
                </tr>
            </tfoot>
        </table>
    </div>
</template>
