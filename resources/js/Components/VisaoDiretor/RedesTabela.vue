<script setup>
/**
 * As redes de uma categoria (Maiores redes). Uma linha por rede do mercado, com o que a
 * diretoria pediu ao lado das colunas da planilha (empresa, UF, lojas, base): vendedor,
 * última compra e status. A linha abre e mostra os clientes e leads (`RedeVinculos`).
 *
 * Vendedor da rede = o que atende mais clientes dela; "(+N)" quando há outros.
 *
 * Coluna Receita: a rede NO MERCADO existe? (`SituacaoDaRede`, decidido no servidor) —
 * Ativa, Rede fechada ou Sem informação, com o tamanho dela hoje na Receita embaixo. É
 * pesquisa de mercado: as filiais dos NOSSOS clientes não entram (Tony, 2026-10-17).
 */
import { ref } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import RedeVinculos from '@/Components/VisaoDiretor/RedeVinculos.vue';
import { ROTULOS_STATUS_REDE, TONS_SITUACAO_REDE, TONS_STATUS_REDE } from '@/constants/visaoDiretor';
import { ROTULOS_SITUACAO_RECEITA, dataDaSituacao } from '@/constants/receita.js';
import { formatDataCurta, formatInteiro } from '@/utils/formato';

defineProps({
    linhas: { type: Array, required: true },
});

const emit = defineEmits(['atualizou']);

const aberta = ref(null);

function alternar(id) {
    aberta.value = aberta.value === id ? null : id;
}

function tituloReceita(r) {
    const desde = dataDaSituacao(r.cnpjSituacaoData);
    return [
        r.situacaoRedeRotulo,
        r.cnpj ? `${r.cnpjRazaoSocial ?? ''} · ${r.cnpj}` : r.cnpjConfiancaRotulo,
        r.cnpjSituacao ? `Empresa ${ROTULOS_SITUACAO_RECEITA[r.cnpjSituacao] ?? r.cnpjSituacao}${desde ? ` desde ${desde}` : ''}` : null,
        r.lojasComNomeAtivas ? `${formatInteiro(r.lojasComNomeAtivas)} lojas ativas com o nome na Receita` : null,
    ].filter(Boolean).join('\n');
}

// Origem da informação, na linha de baixo.
function origemReceita(r) {
    if (r.cnpjLojasAtivas) return `${formatInteiro(r.cnpjLojasAtivas)} lojas ativas`;
    if (r.lojasComNomeAtivas) return `${formatInteiro(r.lojasComNomeAtivas)} lojas com o nome`;

    return null;
}

const QUALIDADE = {
    estimativa: 'Estimativa',
    planilha_anterior: 'Planilha anterior (sem dado novo)',
    pesquisado: 'Pesquisado em out/26',
    sem_numero: 'Sem número público de lojas',
};
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl tbl-cartoes sm:min-w-[1220px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Rede</th>
                    <th class="tbl-th">UF</th>
                    <th class="tbl-th" title="Lojas da rede no Brasil (planilha da diretoria)">Lojas</th>
                    <th class="tbl-th" title="Data do número de lojas">Base</th>
                    <th class="tbl-th" title="A rede existe no mercado? (Receita)">Receita</th>
                    <th class="tbl-th" title="Clientes nossos desta rede (códigos) e filiais">Nossos clientes</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">Última compra</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Abrir</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="r in linhas" :key="r.id">
                    <tr class="tbl-row cursor-pointer" @click="alternar(r.id)">
                        <td class="tbl-td tbl-td-titulo">
                            <span class="tbl-main text-gray-800 sm:max-w-[360px]" :title="r.nome">{{ r.nome }}</span>
                            <span v-if="r.linhaOriginal" class="tbl-sub mx-auto truncate sm:max-w-[360px]" :title="r.linhaOriginal">Planilha: {{ r.linhaOriginal }}</span>
                        </td>
                        <td class="tbl-td" data-rotulo="UF">{{ r.uf || '—' }}</td>
                        <td class="tbl-td tabular-nums" data-rotulo="Lojas" :title="[QUALIDADE[r.qualidade], r.fonte].filter(Boolean).join(' · ') || null">
                            <template v-if="r.lojas !== null">
                                {{ formatInteiro(r.lojas) }}<span v-if="QUALIDADE[r.qualidade]" class="ml-0.5 text-amber-dark">*</span>
                            </template>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td text-xs" data-rotulo="Base">{{ r.dataBase || '—' }}</td>
                        <td class="tbl-td" data-rotulo="Receita" :title="tituloReceita(r)">
                            <StatusPill :tone="TONS_SITUACAO_REDE[r.situacaoRede]" size="sm">{{ r.situacaoRedeRotulo }}</StatusPill>
                            <span v-if="origemReceita(r)" class="tbl-sub">{{ origemReceita(r) }}</span>
                        </td>
                        <td class="tbl-td tabular-nums" data-rotulo="Nossos clientes">
                            <template v-if="r.clientes || r.leads">
                                <span class="tbl-main">{{ formatInteiro(r.clientes) }} {{ r.clientes === 1 ? 'cliente' : 'clientes' }}</span>
                                <span class="tbl-sub">{{ formatInteiro(r.filiais) }} filiais{{ r.leads ? ` · ${formatInteiro(r.leads)} ${r.leads === 1 ? 'lead' : 'leads'}` : '' }}</span>
                            </template>
                            <span v-else class="text-gray-400">nenhum</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Vendedor">
                            <span v-if="r.vendedor" class="tbl-trunc sm:max-w-[200px]" :title="r.vendedor">{{ r.vendedor }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="tbl-td tabular-nums" data-rotulo="Última compra">{{ formatDataCurta(r.ultimaCompra) }}</td>
                        <td class="tbl-td" data-rotulo="Status">
                            <StatusPill :tone="TONS_STATUS_REDE[r.status]" size="sm">{{ ROTULOS_STATUS_REDE[r.status] }}</StatusPill>
                        </td>
                        <td class="tbl-td" data-rotulo="Abrir">
                            <div class="tbl-acoes">
                                <button
                                    type="button"
                                    class="tbl-acao tbl-acao-neutro"
                                    :title="aberta === r.id ? 'Fechar' : 'Ver clientes e vincular'"
                                    :aria-expanded="aberta === r.id"
                                    @click.stop="alternar(r.id)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="transition" :class="aberta === r.id ? 'rotate-180' : ''">
                                        <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="aberta === r.id">
                        <td colspan="10" class="p-0">
                            <RedeVinculos :rede="r" @atualizou="(n) => emit('atualizou', n)" />
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
