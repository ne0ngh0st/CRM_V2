<script setup>
/**
 * A tabela de um segmento da Visão Diretor (2026-10-14): clientes e leads, uma linha por
 * cliente (mesmo grão da Carteira agrupada) ou por lead. Cinco colunas, de propósito — a
 * página é para o dono da empresa ver onde atuamos, deixamos de atuar e nunca atuamos.
 * A ordem (capital social) vem do servidor e não aparece aqui.
 *
 * Todo nome leva ao CRM: cliente → ficha; lead → /leads; filiais → essas filiais na Carteira.
 *
 * Com busca, a lista atravessa os segmentos (o cadastro do TOTVS erra segmento): aparece a
 * coluna Segmento, e a linha de OUTRO segmento que não o da aba fica marcada em âmbar.
 */
import { Link } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_CONTA, TONS_STATUS_CONTA } from '@/constants/visaoDiretor';
import { formatInteiro, nomeCurto } from '@/utils/formato';

defineProps({
    linhas: { type: Array, required: true },
    // Posição (na lista inteira) da primeira linha desta página.
    posicaoInicial: { type: Number, default: 1 },
    // Código do segmento da aba: a linha de outro segmento é marcada.
    segmento: { type: String, default: '' },
    // A busca atravessa os segmentos: mostra a coluna Segmento.
    mostrarSegmento: { type: Boolean, default: false },
});

// Cliente → a empresa inteira na Carteira (pela raiz do CNPJ); lead → /leads.
// Os dois botões da linha: quem abre o cartão/mapa é a página (`MaioresPorSegmento.vue`).
const emit = defineEmits(['cartao-cnpj', 'mapa']);

function hrefDaLinha(l) {
    return l.tipo === 'cliente'
        ? route('carteira.index', { busca: l.buscaCarteira })
        : route('leads.index', { busca: l.documento || l.nome });
}
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl tbl-cartoes sm:min-w-[1020px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Nome</th>
                    <th class="tbl-th" title="Grupo do cliente no TOTVS (da matriz)">Grupo</th>
                    <th v-if="mostrarSegmento" class="tbl-th" title="Segmento do cadastro no TOTVS">Segmento</th>
                    <th class="tbl-th">UF</th>
                    <th class="tbl-th" title="Filiais do cliente no segmento da linha">Filiais</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr
                    v-for="(l, i) in linhas"
                    :key="l.chave"
                    class="tbl-row"
                    :class="mostrarSegmento && l.segmentoCodigo !== segmento ? 'bg-amber/5' : ''"
                >
                    <!--
                        RAZÃO SOCIAL em cima: é única por empresa. Com a marca em cima, as
                        empresas do mesmo grupo (Carrefour, Bompreço, Comercial de Alimentos
                        Carrefour…) apareciam todas como "CARREFOUR" e pareciam duplicadas (Tony,
                        14/10/2026). Embaixo a marca e o CNPJ.
                    -->
                    <td class="tbl-td tbl-td-titulo">
                        <Link :href="hrefDaLinha(l)" class="tbl-main text-teal hover:underline sm:max-w-[380px]" :title="l.nome">
                            <span class="mr-1 tabular-nums text-gray-400">{{ posicaoInicial + i }}.</span>{{ l.nome }}
                        </Link>
                        <span v-if="l.nomeFantasia" class="tbl-sub mx-auto truncate sm:max-w-[380px]" :title="l.nomeFantasia">{{ l.nomeFantasia }}</span>
                        <span class="tbl-sub">{{ l.documento || 'sem CNPJ' }} · {{ l.tipo === 'lead' ? 'Lead' : 'Cliente' }}</span>
                    </td>
                    <td class="tbl-td" data-rotulo="Grupo">
                        <span v-if="l.grupo" class="tbl-trunc sm:max-w-[200px]" :title="l.grupo">{{ l.grupo }}</span>
                        <span v-else class="text-gray-400">—</span>
                    </td>
                    <td v-if="mostrarSegmento" class="tbl-td" data-rotulo="Segmento">
                        <StatusPill
                            v-if="l.segmentoCodigo !== segmento"
                            tone="warn"
                            size="sm"
                            :title="`Cadastrado em ${l.segmentoNome}, não neste segmento`"
                        >{{ l.segmentoNome }}</StatusPill>
                        <span v-else>{{ l.segmentoNome }}</span>
                    </td>
                    <td class="tbl-td" data-rotulo="UF">{{ l.uf || '—' }}</td>
                    <td class="tbl-td tabular-nums" data-rotulo="Filiais">
                        <Link
                            v-if="l.filiais !== null"
                            :href="route('carteira.index', { busca: l.buscaCarteira, segmento: l.segmentoCodigo, agrupar: 0 })"
                            class="tbl-main font-semibold text-teal hover:underline"
                            title="Abrir estas filiais na Carteira"
                        >{{ formatInteiro(l.filiais) }}</Link>
                        <span v-else class="text-gray-400">—</span>
                    </td>
                    <td class="tbl-td" data-rotulo="Status">
                        <StatusPill :tone="TONS_STATUS_CONTA[l.status]" size="sm">{{ ROTULOS_STATUS_CONTA[l.status] }}</StatusPill>
                    </td>
                    <td class="tbl-td" data-rotulo="Vendedor">
                        <span v-if="l.vendedor" class="tbl-main sm:max-w-[180px]" :title="l.vendedor">{{ nomeCurto(l.vendedor) }}</span>
                        <span v-else class="text-gray-400" :title="l.tipo === 'lead' ? 'Lead sem dono: só admin e diretor o veem' : null">—</span>
                    </td>
                    <!-- Endereço e cartão são da Receita: sem CNPJ de 14 dígitos (CPF, vazio), não há. -->
                    <td class="tbl-td" data-rotulo="Ações">
                        <div class="tbl-acoes">
                            <button
                                type="button"
                                class="tbl-acao tbl-acao-teal"
                                :title="l.temCnpj ? 'Ver no Google Maps (endereço da Receita)' : 'Sem CNPJ: sem endereço da Receita'"
                                :disabled="! l.temCnpj"
                                @click="emit('mapa', l)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 21s-6.5-5.6-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21Z" stroke-linejoin="round" />
                                    <circle cx="12" cy="10.5" r="2.3" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="tbl-acao tbl-acao-navy"
                                :title="l.temCnpj ? 'Verificar cartão CNPJ' : 'Sem CNPJ de 14 dígitos'"
                                :disabled="! l.temCnpj"
                                @click="emit('cartao-cnpj', l)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="5" width="18" height="14" rx="1.5" />
                                    <circle cx="8.5" cy="11" r="2" />
                                    <path d="M5.5 16c.6-1.5 1.7-2.2 3-2.2s2.4.7 3 2.2M14 10h4.5M14 13.5h3" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
