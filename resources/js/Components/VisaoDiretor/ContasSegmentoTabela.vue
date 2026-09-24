<script setup>
/**
 * As contas-alvo de um segmento.
 *
 * 🥇 A razão de isto não ser um Power BI: todo número leva a uma lista viva do CRM.
 *   - "N clientes"    → Carteira agrupada com `?conta_alvo=` (mesmo total, há teste)
 *   - cada vendedor   → a carteira DELE dentro da conta
 *   - linha expandida → os clientes, cada um com link para a ficha
 */
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA } from '@/constants/carteira';
import { ROTULOS_STATUS_CONTA, TONS_STATUS_CONTA } from '@/constants/visaoDiretor';
import { ROTULOS_ETAPA_LEAD } from '@/constants/leads';
import { formatDataCurta, formatInteiro } from '@/utils/formato';

const props = defineProps({
    contas: { type: Array, required: true },
});

const emit = defineEmits(['editar', 'excluir', 'historico', 'gerar-lead']);

/*
 * A lista expandida é guardada por conta para não refazer a requisição a cada abre-fecha.
 * ⚠️ Esvaziada sempre que as contas chegam de novo do servidor (salvou, filtrou): um
 * vínculo editado mudaria os clientes, e a linha expandida mostraria a lista antiga.
 */
watch(() => props.contas, () => {
    clientesPorConta.value = {};
});

/** Quantos vendedores aparecem por extenso antes do "+N". Coluna estreita de propósito. */
const VENDEDORES_VISIVEIS = 1;

const expandida = ref(null);
const carregando = ref(null);
const erro = ref(null);
const clientesPorConta = ref({});

async function alternar(conta) {
    if (expandida.value === conta.id) {
        expandida.value = null;

        return;
    }

    expandida.value = conta.id;
    erro.value = null;

    if (clientesPorConta.value[conta.id]) return;

    carregando.value = conta.id;

    try {
        const resposta = await fetch(route('visao-diretor.maiores.clientes', conta.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! resposta.ok) throw new Error(`HTTP ${resposta.status}`);

        clientesPorConta.value[conta.id] = await resposta.json();
    } catch {
        erro.value = conta.id;
    } finally {
        carregando.value = null;
    }
}

</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl tbl-cartoes sm:min-w-[1080px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th w-8" />
                    <th class="tbl-th">Conta</th>
                    <th class="tbl-th">UF</th>
                    <th class="tbl-th" title="Filiais que a rede tem no mercado">Filiais</th>
                    <th class="tbl-th" title="Clientes desta rede na nossa carteira">Clientes</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th w-[7.5rem]">Atendimento</th>
                    <th class="tbl-th">Observação</th>
                    <th class="tbl-th">Última compra</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="conta in contas" :key="conta.id">
                    <tr class="tbl-row cursor-pointer" @click="alternar(conta)">
                        <td class="tbl-td tbl-td-oculto">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="mx-auto h-3.5 w-3.5 text-gray-400 transition"
                                :class="expandida === conta.id ? 'rotate-90' : ''"
                            >
                                <path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </td>
                        <td class="tbl-td tbl-td-titulo">
                            <span class="tbl-main sm:max-w-[240px]" :title="conta.nome">
                                {{ conta.nome }}
                                <span
                                    v-if="conta.temSugestao"
                                    class="ml-1 inline-block h-2 w-2 rounded-full bg-amber align-middle"
                                    title="Vínculo sugerido pela carga da planilha — revisar em Editar"
                                />
                            </span>
                            <a
                                v-if="conta.site"
                                :href="`https://${conta.site.replace(/^https?:\/\//, '')}`"
                                target="_blank" rel="noopener"
                                class="tbl-sub hover:text-teal hover:underline"
                                @click.stop
                            >{{ conta.site }}</a>
                        </td>
                        <td class="tbl-td" data-rotulo="UF">{{ conta.uf || '—' }}</td>
                        <td class="tbl-td tabular-nums" data-rotulo="Filiais (mercado)">
                            {{ conta.filiaisMercado !== null ? formatInteiro(conta.filiaisMercado) : '—' }}
                        </td>
                        <td class="tbl-td" data-rotulo="Clientes" @click.stop>
                            <Link
                                v-if="conta.clientes"
                                :href="route('carteira.index', { conta_alvo: conta.id })"
                                class="tbl-main font-semibold text-teal hover:underline"
                                title="Abrir estes clientes na Carteira"
                            >{{ formatInteiro(conta.clientes) }}</Link>
                            <span v-else class="text-gray-400">0</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Status">
                            <StatusPill :tone="TONS_STATUS_CONTA[conta.status]" size="sm">
                                {{ ROTULOS_STATUS_CONTA[conta.status] ?? conta.status }}
                            </StatusPill>
                        </td>
                        <td class="tbl-td" data-rotulo="Atendimento" @click.stop>
                            <!--
                                Conta sem loja nossa mas com lead aberto pela diretoria: quem
                                atende é o responsável do lead. Leva ao lead na tela de Leads.
                            -->
                            <Link
                                v-if="! conta.atendimento.length && conta.leadAberto"
                                :href="route('leads.index', { busca: conta.leadAberto.nome })"
                                class="inline-flex max-w-[7rem] items-center gap-1 truncate rounded border border-amber bg-amber/10 px-1 py-0.5 text-[0.7rem] text-amber-dark hover:underline"
                                :title="`Lead aberto com ${conta.leadAberto.responsavel} (${ROTULOS_ETAPA_LEAD[conta.leadAberto.etapa] ?? conta.leadAberto.etapa}) — abrir em Leads`"
                            >Lead · {{ conta.leadAberto.responsavel }}</Link>
                            <div v-else-if="conta.atendimento.length" class="flex flex-wrap items-center justify-center gap-0.5">
                                <Link
                                    v-for="v in conta.atendimento.slice(0, VENDEDORES_VISIVEIS)"
                                    :key="v.codVendedor"
                                    :href="route('carteira.index', { conta_alvo: conta.id, visao_vendedor: v.codVendedor })"
                                    class="max-w-[5.5rem] truncate rounded border border-gray-200 bg-gray-50 px-1 py-0.5 text-[0.7rem] text-gray-700 hover:border-teal hover:text-teal"
                                    :title="`${v.nome} — abrir na Carteira`"
                                >{{ v.nome }}</Link>
                                <span
                                    v-if="conta.atendimento.length > VENDEDORES_VISIVEIS"
                                    class="rounded px-1 py-0.5 text-[0.7rem] text-gray-500"
                                    :title="conta.atendimento.slice(VENDEDORES_VISIVEIS).map((v) => v.nome).join(' · ')"
                                >+{{ conta.atendimento.length - VENDEDORES_VISIVEIS }}</span>
                            </div>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Observação" @click.stop>
                            <button
                                v-if="conta.observacao || conta.versoesObservacao"
                                type="button"
                                class="group w-full"
                                title="Ver o histórico da observação"
                                @click="emit('historico', conta)"
                            >
                                <span
                                    class="tbl-trunc block truncate group-hover:text-teal sm:max-w-[220px]"
                                    :class="conta.observacao ? '' : 'italic text-gray-400'"
                                >{{ conta.observacao || 'apagada' }}</span>
                                <span v-if="conta.versoesObservacao > 1" class="tbl-sub group-hover:text-teal">
                                    {{ conta.versoesObservacao }} versões
                                </span>
                            </button>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Última compra">{{ formatDataCurta(conta.ultimaCompra) }}</td>
                        <td class="tbl-td tbl-td-acoes" @click.stop>
                            <div class="tbl-acoes">
                                <button
                                    v-if="conta.status === 'lead' && ! conta.leadAberto"
                                    type="button"
                                    class="tbl-acao tbl-acao-amber"
                                    title="Gerar lead com responsável"
                                    @click="emit('gerar-lead', conta)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <circle cx="9" cy="8" r="3.5" />
                                        <path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke-linecap="round" />
                                        <path d="M18 8v6M15 11h6" stroke-linecap="round" />
                                    </svg>
                                </button>
                                <button type="button" class="tbl-acao tbl-acao-teal" title="Editar conta e vínculos" @click="emit('editar', conta)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <path d="M4 20h4L19 9l-4-4L4 16v4Z" stroke-linejoin="round" />
                                        <path d="m13.5 6.5 4 4" />
                                    </svg>
                                </button>
                                <button type="button" class="tbl-acao tbl-acao-danger" title="Excluir conta" @click="emit('excluir', conta)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="expandida === conta.id">
                        <td colspan="10" class="tbl-td tbl-td-expansao bg-gray-50/60">
                            <p v-if="carregando === conta.id" class="py-3 text-xs text-gray-400">Carregando clientes…</p>
                            <p v-else-if="erro === conta.id" class="py-3 text-xs text-red-700">Não foi possível carregar os clientes.</p>
                            <p v-else-if="! conta.vinculos.length" class="py-3 text-xs text-gray-500">
                                Nenhum vínculo: esta rede ainda não está ligada a nenhum cliente do CRM. Use
                                <button type="button" class="font-semibold text-teal hover:underline" @click="emit('editar', conta)">Editar</button>
                                para vincular grupos ou clientes.
                            </p>
                            <template v-else-if="clientesPorConta[conta.id]">
                                <p v-if="! clientesPorConta[conta.id].total" class="py-3 text-xs text-gray-500">
                                    Os vínculos desta conta não casam nenhum cliente da carteira.
                                </p>
                                <template v-else>
                                    <div class="overflow-x-auto">
                                        <table class="tbl-itens min-w-[760px]">
                                            <thead>
                                                <tr class="tbl-itens-head-row">
                                                    <th class="tbl-itens-th">Cliente</th>
                                                    <th class="tbl-itens-th">Código</th>
                                                    <th class="tbl-itens-th">Grupo</th>
                                                    <th class="tbl-itens-th">Vendedor</th>
                                                    <th class="tbl-itens-th">Lojas</th>
                                                    <th class="tbl-itens-th">Última compra</th>
                                                    <th class="tbl-itens-th">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="tbl-body">
                                                <tr v-for="c in clientesPorConta[conta.id].clientes" :key="c.codCliente" class="tbl-itens-row">
                                                    <td class="tbl-itens-td">
                                                        <Link :href="route('carteira.detalhes', c.id)" class="font-medium text-teal hover:underline" :title="c.razaoSocial">
                                                            {{ c.nome }}
                                                        </Link>
                                                    </td>
                                                    <td class="tbl-itens-td tabular-nums">{{ c.codCliente }}</td>
                                                    <td class="tbl-itens-td">{{ c.grupo || '—' }}</td>
                                                    <td class="tbl-itens-td">{{ c.vendedor || '—' }}</td>
                                                    <td class="tbl-itens-td tabular-nums">{{ c.lojas }}</td>
                                                    <td class="tbl-itens-td">{{ formatDataCurta(c.ultimaCompra) }}</td>
                                                    <td class="tbl-itens-td">
                                                        <StatusPill :tone="TONS_STATUS_CARTEIRA[c.status]" size="sm">
                                                            {{ ROTULOS_STATUS_CARTEIRA[c.status] ?? c.status }}
                                                        </StatusPill>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">
                                        <template v-if="clientesPorConta[conta.id].total > clientesPorConta[conta.id].clientes.length">
                                            Mostrando os {{ clientesPorConta[conta.id].clientes.length }} com compra mais recente de
                                            {{ clientesPorConta[conta.id].total }} clientes.
                                        </template>
                                        <Link :href="route('carteira.index', { conta_alvo: conta.id })" class="font-semibold text-teal hover:underline">
                                            Ver todos na Carteira →
                                        </Link>
                                    </p>
                                </template>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
