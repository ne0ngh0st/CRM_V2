<script setup>
/**
 * /atualizacoes → "Leads da prospecção": a última rodada do `totvs:import-leads`.
 *
 * O que interessa aqui é sobretudo o que NÃO entrou e por quê — quem monta a planilha
 * precisa saber que 300 CNPJs eram clientes, ou que 40 estão baixados na Receita, para
 * não procurar o lead na tela à toa.
 *
 * ⚠️ Os números vêm prontos do comando (`leads_importacoes.resultado`); as chaves lidas
 * aqui são o contrato com `ImportLeadsTotvs`. Esta tela não recalcula nada.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_SITUACAO_RECEITA } from '@/constants/receita.js';
import { dataHora, formatInteiro } from '@/utils/formato';

const props = defineProps({
    importacao: { type: Object, required: true },
});

const r = computed(() => props.importacao.resultado ?? {});
const contas = computed(() => r.value.contas ?? {});
const naReceita = computed(() => Object.values(r.value.receitaPorSituacao ?? {}).reduce((a, b) => a + b, 0));

const subtitulo = computed(() => {
    const quando = dataHora(props.importacao.em);

    return props.importacao.simulacao ? `Simulação de ${quando} — nada foi gravado` : `Importação de ${quando}`;
});
</script>

<template>
    <DarkCard title="Leads da prospecção" :subtitle="subtitulo">
        <template #icon>
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <circle cx="9" cy="7" r="3" />
                <path stroke-linecap="round" d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6M19 8v6M16 11h6" />
            </svg>
        </template>

        <template #actions>
            <StatusPill v-if="importacao.status === 'falhou'" tone="danger" size="sm">Falhou</StatusPill>
            <StatusPill v-else-if="importacao.simulacao" tone="warn" size="sm">Simulação</StatusPill>
            <StatusPill v-else tone="ok" size="sm">Importado</StatusPill>
        </template>

        <div class="space-y-4">
            <p v-if="importacao.status === 'falhou'" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                {{ importacao.erro }}
            </p>

            <p v-if="importacao.simulacao" class="text-xs text-gray-500">
                Última importação de verdade:
                <strong>{{ importacao.ultimaReal ? dataHora(importacao.ultimaReal) : 'nenhuma ainda' }}</strong>.
            </p>

            <template v-if="importacao.status !== 'falhou'">
                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Lido · {{ importacao.arquivos.length }} arquivo{{ importacao.arquivos.length === 1 ? '' : 's' }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.linhas)" label="Linhas" compact />
                        <KpiTile :value="formatInteiro(r.cnpjs)" label="CNPJs distintos" compact />
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Entraram</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.novos)" label="Leads novos" tone="ok" compact />
                        <KpiTile :value="formatInteiro(r.atualizados)" label="Já no CRM, atualizados" compact />
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Não entraram</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.jaClientes)" label="Já eram clientes (TOTVS)" tone="info" compact />
                        <KpiTile :value="formatInteiro(naReceita)" label="CNPJ não ativo na Receita" tone="danger" compact />
                        <KpiTile :value="formatInteiro(r.segurados)" label="Esperando a Receita" :tone="r.segurados ? 'warn' : 'default'" compact />
                        <KpiTile :value="formatInteiro(r.recusadas)" label="Linhas fora do padrão" :tone="r.recusadas ? 'danger' : 'default'" compact />
                        <KpiTile :value="formatInteiro(r.deOutraOrigem)" label="Já eram lead manual/site" compact />
                    </div>
                    <p v-if="naReceita" class="mt-1 text-[0.7rem] text-gray-500">
                        Na Receita:
                        <span v-for="(n, situacao, i) in r.receitaPorSituacao" :key="situacao">
                            {{ i ? ' · ' : '' }}{{ ROTULOS_SITUACAO_RECEITA[situacao] ?? situacao }} {{ formatInteiro(n) }}
                        </span>
                    </p>
                    <p v-if="r.segurados" class="mt-1 text-[0.7rem] text-amber-dark">
                        CNPJ que a base da Receita ainda não conhece fica segurado e entra sozinho no
                        import seguinte à carga da Receita.
                    </p>
                    <p v-if="r.leadsQueViraramCliente" class="mt-1 text-[0.7rem] text-gray-500">
                        Além disso, {{ formatInteiro(r.leadsQueViraramCliente) }} lead(s) que já estavam no CRM viraram
                        cliente e não foram mexidos.
                    </p>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Maiores por Segmento</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(contas.criadas ?? 0)" label="Redes criadas" compact />
                        <KpiTile :value="formatInteiro(contas.confirmados ?? 0)" label="Leads ligados a rede" compact />
                        <KpiTile
                            :value="formatInteiro(importacao.sugestoesPendentes)"
                            label="Sugestões para confirmar (agora)"
                            :tone="importacao.sugestoesPendentes ? 'warn' : 'default'"
                            :href="importacao.sugestoesPendentes ? route('visao-diretor.maiores.index') : null"
                            compact
                        />
                    </div>
                </div>

                <p class="border-t border-gray-200 pt-3 text-xs text-gray-500">
                    <Link :href="route('leads.index', { origem: 'prospeccao' })" class="font-medium text-teal hover:underline">
                        {{ formatInteiro(importacao.prospeccaoNoCrm) }} leads da prospecção no CRM hoje
                    </Link>
                    <span v-if="r.sumiram"> · {{ formatInteiro(r.sumiram) }} da base antiga não estão nos CSVs (mantidos)</span>
                </p>
            </template>

            <details v-if="importacao.arquivos.length" class="text-xs">
                <summary class="cursor-pointer font-medium text-gray-600">Arquivos lidos</summary>
                <ul class="mt-1 divide-y divide-gray-100">
                    <li v-for="a in importacao.arquivos" :key="a.nome" class="flex justify-between gap-2 py-1">
                        <span class="truncate text-gray-700">{{ a.nome }}</span>
                        <span class="shrink-0 tabular-nums text-gray-500">{{ formatInteiro(a.linhas) }} linhas</span>
                    </li>
                </ul>
            </details>

            <details v-if="importacao.recusadas.length" class="text-xs" open>
                <summary class="cursor-pointer font-medium text-red-700">
                    Linhas fora do padrão ({{ formatInteiro(r.recusadas ?? importacao.recusadas.length) }}) — corrigir na planilha
                </summary>
                <ul class="mt-1 max-h-60 divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="(linha, i) in importacao.recusadas" :key="i" class="py-1 text-gray-700">{{ linha }}</li>
                </ul>
                <p v-if="(r.recusadas ?? 0) > importacao.recusadas.length" class="mt-1 text-gray-500">
                    Mostrando as {{ importacao.recusadas.length }} primeiras.
                </p>
            </details>
        </div>
    </DarkCard>
</template>
