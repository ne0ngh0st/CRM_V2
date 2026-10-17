<script setup>
/**
 * A linha aberta de uma rede (Maiores redes): os clientes e leads dela, com responsável,
 * última compra e status de cada um, e — para admin/diretor — vincular e remover à mão.
 *
 * Os itens vêm do servidor ao abrir (`visao-diretor.redes.vinculos`). Vincular/remover
 * devolvem a lista nova e os contadores da rede, que a página aplica na linha sem
 * recarregar a tabela (`atualizou`).
 *
 * ⚠️ Remover grava a recusa: a reconstrução automática não religa ESTE cliente a ESTA
 * rede. Vincular move o cliente se ele estava em outra (cada cliente, uma rede só).
 *
 * No topo, o CNPJ da PRÓPRIA rede na Receita, com a correção à mão: outro CNPJ, ou "sem
 * CNPJ único" (franquia, bandeira de outra empresa). O informado à mão a carga mensal não
 * sobrescreve.
 */
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_NIVEL_VINCULO, ROTULOS_STATUS_REDE, TONS_STATUS_REDE } from '@/constants/visaoDiretor';
import { ROTULOS_SITUACAO_RECEITA, TONS_SITUACAO_RECEITA, dataDaSituacao } from '@/constants/receita.js';
import { deleteJson, postJson, putJson } from '@/utils/csrf';
import { formatDataCurta, formatInteiro, nomeCurto } from '@/utils/formato';

const props = defineProps({
    rede: { type: Object, required: true },
});

const emit = defineEmits(['atualizou']);

const dados = ref(null);
const erro = ref('');
const ocupado = ref(false);

async function carregar() {
    erro.value = '';
    try {
        const r = await fetch(route('visao-diretor.redes.vinculos', props.rede.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (! r.ok) throw new Error();
        dados.value = await r.json();
    } catch {
        erro.value = 'Não foi possível carregar os clientes desta rede.';
    }
}

onMounted(carregar);

async function aplicar(resposta) {
    if (! resposta.ok) {
        const corpo = await resposta.json().catch(() => ({}));
        erro.value = corpo.message || 'Não foi possível salvar. Tente de novo.';

        return;
    }
    const corpo = await resposta.json();
    dados.value = { clientes: corpo.clientes, leads: corpo.leads };
    emit('atualizou', corpo.rede);
}

async function remover(tipo, codigo) {
    ocupado.value = true;
    try {
        await aplicar(await deleteJson(route('visao-diretor.redes.remover', props.rede.id), { tipo, codigo }));
    } finally {
        ocupado.value = false;
    }
}

// ─── CNPJ da rede ────────────────────────────────────────────────────────────
const editandoCnpj = ref(false);
const cnpjDigitado = ref('');
const erroCnpj = ref('');

async function salvarCnpj(cnpj) {
    erroCnpj.value = '';
    ocupado.value = true;
    try {
        const r = await putJson(route('visao-diretor.redes.cnpj', props.rede.id), { cnpj });
        const corpo = await r.json().catch(() => ({}));
        if (! r.ok) {
            erroCnpj.value = corpo.message || 'Não foi possível salvar o CNPJ.';

            return;
        }
        editandoCnpj.value = false;
        cnpjDigitado.value = '';
        emit('atualizou', corpo.rede);
    } finally {
        ocupado.value = false;
    }
}

// ─── Vincular ────────────────────────────────────────────────────────────────
const termo = ref('');
const resultados = ref([]);
const buscando = ref(false);
let atraso;

function aoDigitar() {
    clearTimeout(atraso);
    if (termo.value.trim().length < 3) {
        resultados.value = [];

        return;
    }
    atraso = setTimeout(async () => {
        buscando.value = true;
        try {
            const r = await fetch(route('visao-diretor.redes.busca', { termo: termo.value }), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            resultados.value = r.ok ? (await r.json()).resultados : [];
        } finally {
            buscando.value = false;
        }
    }, 300);
}

async function vincular(item) {
    ocupado.value = true;
    try {
        await aplicar(await postJson(route('visao-diretor.redes.vincular', props.rede.id), { tipo: item.tipo, codigo: item.codigo }));
        termo.value = '';
        resultados.value = [];
    } finally {
        ocupado.value = false;
    }
}
</script>

<template>
    <div class="space-y-3 bg-gray-50/70 px-3 py-3 text-left">
        <p v-if="erro" class="text-xs text-red-700">{{ erro }}</p>
        <p v-if="! dados && ! erro" class="text-xs text-gray-400">Carregando…</p>

        <!-- CNPJ da própria rede na Receita. -->
        <div class="flex flex-wrap items-start gap-x-6 gap-y-2 rounded border border-gray-200 bg-white px-3 py-2">
            <div class="min-w-0">
                <p class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">CNPJ da rede na Receita</p>
                <template v-if="rede.cnpj">
                    <p class="text-xs font-medium text-gray-800">{{ rede.cnpjRazaoSocial || 'Razão social não informada' }}</p>
                    <p class="flex flex-wrap items-center gap-2 text-[0.68rem] text-gray-500">
                        <span class="tabular-nums">{{ rede.cnpj }}</span>
                        <StatusPill v-if="rede.cnpjSituacao" :tone="TONS_SITUACAO_RECEITA[rede.cnpjSituacao] ?? 'neutral'" size="sm">
                            {{ ROTULOS_SITUACAO_RECEITA[rede.cnpjSituacao] ?? rede.cnpjSituacao }}{{ dataDaSituacao(rede.cnpjSituacaoData) ? ` desde ${dataDaSituacao(rede.cnpjSituacaoData)}` : '' }}
                        </StatusPill>
                        <span v-else>situação chega na próxima carga da Receita</span>
                        <span>· {{ rede.cnpjConfiancaRotulo }}</span>
                        <span v-if="rede.cnpjLojasAtivas !== null">· {{ formatInteiro(rede.cnpjLojasAtivas) }} lojas ativas</span>
                    </p>
                </template>
                <p v-else class="text-xs text-gray-500">
                    {{ rede.cnpjConfianca === 'franquia'
                        ? 'Franquia: cada loja é de um franqueado, não há um CNPJ da rede.'
                        : rede.cnpjConfianca === 'manual' ? 'Sem CNPJ único (definido à mão).' : 'Não identificado na base da Receita.' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <template v-if="editandoCnpj">
                    <input
                        v-model="cnpjDigitado"
                        type="text"
                        inputmode="numeric"
                        placeholder="CNPJ da matriz"
                        class="min-h-11 w-44 rounded border-gray-300 py-1 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                        @keydown.enter.prevent="salvarCnpj(cnpjDigitado)"
                    />
                    <button type="button" class="rounded border border-teal px-2 py-1 text-xs font-medium text-teal hover:bg-teal/10 disabled:opacity-50" :disabled="ocupado || ! cnpjDigitado.trim()" @click="salvarCnpj(cnpjDigitado)">Salvar</button>
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50" :disabled="ocupado" title="Franquia ou bandeira de outra empresa" @click="salvarCnpj(null)">Sem CNPJ único</button>
                    <button type="button" class="px-1 text-xs text-gray-400 hover:text-gray-600" @click="editandoCnpj = false; erroCnpj = ''">Cancelar</button>
                </template>
                <button v-else type="button" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" @click="editandoCnpj = true">Corrigir CNPJ</button>
            </div>
            <p v-if="erroCnpj" class="w-full text-xs text-red-700">{{ erroCnpj }}</p>
        </div>

        <template v-if="dados">
            <!-- Vincular à mão: cliente (nome, CNPJ ou código) ou lead. -->
            <div class="max-w-xl">
                <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Vincular cliente ou lead</label>
                <input
                    v-model="termo"
                    type="search"
                    placeholder="Nome, CNPJ ou código do cliente"
                    class="mt-1 min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                    @input="aoDigitar"
                />
                <p v-if="buscando" class="mt-1 text-xs text-gray-400">Buscando…</p>
                <ul v-else-if="resultados.length" class="mt-1 max-h-64 divide-y divide-gray-100 overflow-y-auto rounded border border-gray-200 bg-white">
                    <li v-for="r in resultados" :key="r.tipo + r.codigo" class="flex items-center justify-between gap-3 px-2 py-1.5">
                        <span class="min-w-0">
                            <span class="block truncate text-xs font-medium text-gray-800" :title="r.nome">{{ r.nome }}</span>
                            <span class="block truncate text-[0.68rem] text-gray-400">
                                {{ r.tipo === 'lead' ? 'Lead' : r.codigo }}{{ r.documento ? ' · ' + r.documento : '' }}{{ r.detalhe ? ' · ' + r.detalhe : '' }}
                            </span>
                            <span v-if="r.redeAtual" class="block text-[0.68rem] text-amber-dark">Hoje em {{ r.redeAtual }}: sai de lá</span>
                        </span>
                        <button
                            type="button"
                            class="shrink-0 rounded border border-teal px-2 py-1 text-xs font-medium text-teal transition hover:bg-teal/10 disabled:opacity-50"
                            :disabled="ocupado || r.redeAtual === rede.nome"
                            @click="vincular(r)"
                        >{{ r.redeAtual === rede.nome ? 'Já está' : 'Vincular' }}</button>
                    </li>
                </ul>
                <p v-else-if="termo.trim().length >= 3" class="mt-1 text-xs text-gray-400">Nada encontrado.</p>
            </div>
            <p v-if="! dados.clientes.length && ! dados.leads.length" class="text-xs text-gray-500">
                Nenhum cliente ou lead ligado a esta rede. Use a busca acima para vincular.
            </p>

            <!-- Rede grande (Raia: 211 clientes): a lista rola dentro da linha, a busca fica em cima. -->
            <div v-if="dados.clientes.length || dados.leads.length" class="tbl-wrap max-h-[28rem] overflow-y-auto">
                <table class="tbl-itens tbl-cartoes w-full sm:min-w-[860px]">
                    <thead>
                        <tr class="tbl-itens-head-row">
                            <th class="tbl-itens-th">Cliente</th>
                            <th class="tbl-itens-th">Filiais</th>
                            <th class="tbl-itens-th">Responsável</th>
                            <th class="tbl-itens-th">Última compra</th>
                            <th class="tbl-itens-th">Status</th>
                            <th class="tbl-itens-th">Como entrou</th>
                            <th class="tbl-itens-th">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="tbl-body">
                        <tr v-for="c in dados.clientes" :key="'c' + c.codigo" class="tbl-itens-row">
                            <td class="tbl-itens-td tbl-td-titulo">
                                <Link :href="route('carteira.detalhes', c.cliente_id)" class="tbl-main text-teal hover:underline sm:max-w-[320px]" :title="c.nome">{{ c.nome }}</Link>
                                <span class="tbl-sub">{{ c.fantasia || '—' }} · {{ c.codigo }}{{ c.documento ? ' · ' + c.documento : '' }}</span>
                            </td>
                            <td class="tbl-itens-td tabular-nums" data-rotulo="Filiais">{{ formatInteiro(c.filiais) }}</td>
                            <td class="tbl-itens-td" data-rotulo="Responsável" :title="c.responsavel">{{ nomeCurto(c.responsavel) || '—' }}</td>
                            <td class="tbl-itens-td tabular-nums" data-rotulo="Última compra">{{ formatDataCurta(c.ultima_compra) }}</td>
                            <td class="tbl-itens-td" data-rotulo="Status">
                                <StatusPill :tone="TONS_STATUS_REDE[c.status]" size="sm">{{ ROTULOS_STATUS_REDE[c.status] }}</StatusPill>
                            </td>
                            <td class="tbl-itens-td text-xs" data-rotulo="Como entrou">{{ ROTULOS_NIVEL_VINCULO[c.nivel] }}</td>
                            <td class="tbl-itens-td" data-rotulo="Ações">
                                <div class="tbl-acoes">
                                    <button type="button" class="tbl-acao tbl-acao-danger" title="Tirar desta rede" :disabled="ocupado" @click="remover('cliente', c.codigo)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="l in dados.leads" :key="'l' + l.id" class="tbl-itens-row">
                            <td class="tbl-itens-td tbl-td-titulo">
                                <Link :href="route('leads.index', { busca: l.documento || l.nome })" class="tbl-main text-teal hover:underline sm:max-w-[320px]" :title="l.nome">{{ l.nome }}</Link>
                                <span class="tbl-sub">Lead{{ l.fantasia ? ' · ' + l.fantasia : '' }}{{ l.documento ? ' · ' + l.documento : '' }}</span>
                            </td>
                            <td class="tbl-itens-td text-gray-400" data-rotulo="Filiais">—</td>
                            <td class="tbl-itens-td" data-rotulo="Responsável" :title="l.responsavel">{{ nomeCurto(l.responsavel) || '—' }}</td>
                            <td class="tbl-itens-td text-gray-400" data-rotulo="Última compra">—</td>
                            <td class="tbl-itens-td" data-rotulo="Status">
                                <StatusPill :tone="TONS_STATUS_REDE[l.status]" size="sm">{{ ROTULOS_STATUS_REDE[l.status] }}</StatusPill>
                            </td>
                            <td class="tbl-itens-td text-xs" data-rotulo="Como entrou">{{ ROTULOS_NIVEL_VINCULO[l.nivel] }}</td>
                            <td class="tbl-itens-td" data-rotulo="Ações">
                                <div class="tbl-acoes">
                                    <button type="button" class="tbl-acao tbl-acao-danger" title="Tirar desta rede" :disabled="ocupado" @click="remover('lead', String(l.id))">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </template>
    </div>
</template>
