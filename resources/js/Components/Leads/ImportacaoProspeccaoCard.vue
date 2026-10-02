<script setup>
/**
 * /atualizacoes → "Leads da prospecção": os botões que rodam o `totvs:import-leads` e a
 * última rodada dele.
 *
 * O que interessa aqui é sobretudo o que NÃO entrou e por quê — quem monta a planilha
 * precisa saber que 300 CNPJs eram clientes, ou que 40 estão baixados na Receita, para
 * não procurar o lead na tela à toa.
 *
 * ⚠️ Os números da rodada vêm prontos do comando (`leads_importacoes.resultado`); as
 * chaves lidas aqui são o contrato com `ImportLeadsTotvs`. Esta tela não recalcula nada.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_SITUACAO_RECEITA } from '@/constants/receita.js';
import { dataHora, formatInteiro } from '@/utils/formato';

const props = defineProps({
    dados: { type: Object, required: true },
});

const ultima = computed(() => props.dados.ultima);
const r = computed(() => ultima.value?.resultado ?? {});
const contas = computed(() => r.value.contas ?? {});
const naReceita = computed(() => Object.values(r.value.receitaPorSituacao ?? {}).reduce((a, b) => a + b, 0));
const terminou = computed(() => ultima.value && ['sucesso', 'falhou', 'travada'].includes(ultima.value.status));
const temResultado = computed(() => ultima.value?.status === 'sucesso' && ultima.value.resultado);

const subtitulo = computed(() => {
    if (props.dados.emAndamento) return 'Rodando agora…';
    if (!ultima.value) return 'Nenhuma importação ainda';

    const quando = dataHora(ultima.value.em);
    const quem = ultima.value.por ? ` por ${ultima.value.por}` : '';

    return ultima.value.simulacao ? `Simulação de ${quando}${quem} — nada foi gravado` : `Importação de ${quando}${quem}`;
});

/*
 * Clique num número → a lista por trás dele, buscada sob demanda (pode ter milhares de
 * linhas; carregar junto da página pesaria em todo recarregamento automático).
 */
const lista = ref({ aberta: false, titulo: '', itens: [], total: 0, carregando: false, erro: null });

async function abrir(chave) {
    if (!ultima.value?.id) return;
    lista.value = { aberta: true, titulo: '', itens: [], total: 0, carregando: true, erro: null };

    try {
        const resposta = await fetch(route('atualizacoes.leads.detalhe', { rodada: ultima.value.id, chave }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!resposta.ok) throw new Error(`HTTP ${resposta.status}`);
        const dados = await resposta.json();
        lista.value = { ...lista.value, ...dados, carregando: false };
    } catch {
        lista.value = { ...lista.value, carregando: false, erro: 'Não foi possível carregar a lista.' };
    }
}

const enviando = ref(false);

function rodar(simulacao) {
    enviando.value = true;
    router.post(route('atualizacoes.leads'), { simulacao }, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; },
    });
}
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
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex min-h-8 items-center rounded border border-white/40 px-3 text-xs font-semibold text-white transition hover:bg-white/10 disabled:opacity-50"
                    :disabled="enviando || dados.emAndamento"
                    title="Lê os CSVs e mostra o que entraria, sem gravar nada"
                    @click="rodar(true)"
                >Simular</button>
                <button
                    type="button"
                    class="inline-flex min-h-8 items-center rounded bg-teal px-3 text-xs font-semibold text-white transition hover:bg-navy disabled:opacity-50"
                    :disabled="enviando || dados.emAndamento"
                    title="Baixa os CSVs da pasta Leads e importa"
                    @click="rodar(false)"
                >{{ dados.emAndamento ? 'Importando…' : 'Importar leads' }}</button>
            </div>
        </template>

        <div class="space-y-4">
            <p v-if="dados.emAndamento" class="rounded border border-amber/60 bg-amber/10 px-3 py-2 text-xs text-gray-700">
                Baixando os CSVs do S3 e importando. A tela se atualiza sozinha.
            </p>

            <p v-else-if="!ultima" class="text-sm text-gray-500">
                Quando o time de prospecção salvar os CSVs na pasta <strong>Leads</strong>, use
                <strong>Simular</strong> para conferir e <strong>Importar leads</strong> para gravar.
            </p>

            <template v-if="ultima && terminou">
                <div class="flex flex-wrap items-center gap-2">
                    <StatusPill v-if="ultima.status === 'falhou'" tone="danger" size="sm">Falhou</StatusPill>
                    <StatusPill v-else-if="ultima.status === 'travada'" tone="danger" size="sm">Interrompida</StatusPill>
                    <StatusPill v-else-if="ultima.simulacao" tone="warn" size="sm">Simulação</StatusPill>
                    <StatusPill v-else tone="ok" size="sm">Importado</StatusPill>
                    <span v-if="ultima.simulacao" class="text-xs text-gray-500">
                        Última importação de verdade:
                        <strong>{{ dados.ultimaReal ? dataHora(dados.ultimaReal) : 'nenhuma ainda' }}</strong>.
                    </span>
                </div>

                <p v-if="ultima.status === 'falhou' && ultima.erro" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {{ ultima.erro }}
                </p>
                <p v-if="ultima.status === 'travada'" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                    A rodada não terminou (o processo foi interrompido). Pode rodar de novo.
                </p>
            </template>

            <template v-if="temResultado">
                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Lido · {{ ultima.arquivos.length }} arquivo{{ ultima.arquivos.length === 1 ? '' : 's' }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.linhas)" label="Linhas" compact />
                        <KpiTile :value="formatInteiro(r.cnpjs)" label="CNPJs distintos" compact />
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Entraram</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.novos)" label="Leads novos" tone="ok" compact :botao="r.novos > 0" @click="r.novos && abrir('novos')" />
                        <KpiTile :value="formatInteiro(r.atualizados)" label="Já no CRM, atualizados" compact :botao="r.atualizados > 0" @click="r.atualizados && abrir('atualizados')" />
                    </div>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Não entraram</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(r.jaClientes)" label="Já eram clientes (TOTVS)" tone="info" compact :botao="r.jaClientes > 0" @click="r.jaClientes && abrir('jaClientes')" />
                        <KpiTile :value="formatInteiro(naReceita)" label="CNPJ não ativo na Receita" tone="danger" compact :botao="naReceita > 0" @click="naReceita && abrir('naoAtivos')" />
                        <KpiTile :value="formatInteiro(r.segurados)" label="Esperando a Receita" :tone="r.segurados ? 'warn' : 'default'" compact :botao="r.segurados > 0" @click="r.segurados && abrir('segurados')" />
                        <KpiTile :value="formatInteiro(r.recusadas)" label="Linhas fora do padrão" :tone="r.recusadas ? 'danger' : 'default'" compact :botao="r.recusadas > 0" @click="r.recusadas && abrir('recusadas')" />
                        <KpiTile :value="formatInteiro(r.deOutraOrigem)" label="Já eram lead manual/site" compact :botao="r.deOutraOrigem > 0" @click="r.deOutraOrigem && abrir('deOutraOrigem')" />
                    </div>
                    <p v-if="naReceita" class="mt-1 text-[0.7rem] text-gray-500">
                        Na Receita:
                        <span v-for="(n, situacao, i) in r.receitaPorSituacao" :key="situacao">
                            {{ i ? ' · ' : '' }}{{ ROTULOS_SITUACAO_RECEITA[situacao] ?? situacao }} {{ formatInteiro(n) }}
                        </span>
                    </p>
                    <p v-if="r.consultadosNaHora" class="mt-1 text-[0.7rem] text-gray-500">
                        {{ formatInteiro(r.consultadosNaHora) }} CNPJ(s) que a base mensal ainda não tinha foram
                        consultados na hora na Receita.
                    </p>
                    <p v-if="r.segurados" class="mt-1 text-[0.7rem] text-amber-dark">
                        Os que ficaram esperando: a Receita não respondeu
                        <template v-if="r.receitaIndisponivel">({{ formatInteiro(r.receitaIndisponivel) }} sem resposta)</template>
                        ou passou do limite de consultas por rodada. Clique em <strong>Importar leads</strong>
                        de novo mais tarde — eles são consultados de novo.
                    </p>
                    <p v-if="r.leadsQueViraramCliente" class="mt-1 text-[0.7rem] text-gray-500">
                        Além disso,
                        <button type="button" class="font-medium text-teal hover:underline" @click="abrir('viraramCliente')">
                            {{ formatInteiro(r.leadsQueViraramCliente) }} lead(s)
                        </button>
                        que já estavam no CRM viraram cliente e não foram mexidos.
                    </p>
                </div>

                <div>
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Maiores por Segmento</p>
                    <div class="flex flex-wrap gap-2">
                        <KpiTile :value="formatInteiro(contas.criadas ?? 0)" label="Redes criadas" compact :botao="contas.criadas > 0" @click="contas.criadas && abrir('redesCriadas')" />
                        <KpiTile :value="formatInteiro(contas.confirmados ?? 0)" label="Leads ligados a rede" compact :botao="contas.confirmados > 0" @click="contas.confirmados && abrir('ligados')" />
                    </div>
                </div>
            </template>

            <!-- Estado de AGORA (não da rodada): muda quando alguém atribui ou confirma. -->
            <div v-if="dados.prospeccaoNoCrm || dados.sugestoesPendentes" class="border-t border-gray-200 pt-3">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Agora no CRM</p>
                <div class="flex flex-wrap gap-2">
                    <KpiTile
                        :value="formatInteiro(dados.prospeccaoNoCrm)"
                        label="Leads da prospecção"
                        :href="route('leads.index', { origem: 'prospeccao' })"
                        compact
                    />
                    <KpiTile
                        :value="formatInteiro(dados.semVendedor)"
                        label="Sem vendedor (atribuir)"
                        :tone="dados.semVendedor ? 'warn' : 'default'"
                        :href="dados.semVendedor ? route('leads.index', { origem: 'prospeccao', sem_vendedor: 1 }) : null"
                        compact
                    />
                    <KpiTile
                        :value="formatInteiro(dados.sugestoesPendentes)"
                        label="Redes para confirmar"
                        :tone="dados.sugestoesPendentes ? 'warn' : 'default'"
                        :href="dados.sugestoesPendentes ? route('visao-diretor.maiores.index') : null"
                        compact
                    />
                </div>
                <p v-if="dados.semVendedor" class="mt-1 text-[0.7rem] text-gray-500">
                    Lead sem vendedor só aparece para admin e diretor. Para atribuir, preencha o
                    <code>cod_vendedor</code> no CSV e importe de novo.
                </p>
                <p v-if="temResultado && r.sumiram" class="mt-1 text-[0.7rem] text-gray-500">
                    {{ formatInteiro(r.sumiram) }} leads da base antiga não estão nos CSVs (mantidos).
                </p>
            </div>

            <details v-if="temResultado && ultima.arquivos.length" class="text-xs">
                <summary class="cursor-pointer font-medium text-gray-600">Arquivos lidos</summary>
                <ul class="mt-1 divide-y divide-gray-100">
                    <li v-for="a in ultima.arquivos" :key="a.nome" class="flex justify-between gap-2 py-1">
                        <span class="truncate text-gray-700">{{ a.nome }}</span>
                        <span class="shrink-0 tabular-nums text-gray-500">{{ formatInteiro(a.linhas) }} linhas</span>
                    </li>
                </ul>
            </details>

            <details v-if="temResultado && ultima.recusadas.length" class="text-xs" open>
                <summary class="cursor-pointer font-medium text-red-700">
                    Linhas fora do padrão ({{ formatInteiro(r.recusadas ?? ultima.recusadas.length) }}) — corrigir na planilha
                </summary>
                <ul class="mt-1 max-h-60 divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="(linha, i) in ultima.recusadas" :key="i" class="py-1 text-gray-700">{{ linha }}</li>
                </ul>
                <p v-if="(r.recusadas ?? 0) > ultima.recusadas.length" class="mt-1 text-gray-500">
                    Mostrando as {{ ultima.recusadas.length }} primeiras.
                </p>
            </details>
        </div>
    </DarkCard>

    <ModalPadrao
        :show="lista.aberta"
        :titulo="lista.titulo || 'Carregando…'"
        :subtitulo="lista.carregando ? '' : (lista.total > lista.itens.length ? `Mostrando ${formatInteiro(lista.itens.length)} de ${formatInteiro(lista.total)}` : `${formatInteiro(lista.itens.length)} item(ns)`)"
        max-width="2xl"
        @close="lista.aberta = false"
    >
        <p v-if="lista.carregando" class="py-6 text-center text-sm text-gray-500">Carregando…</p>
        <p v-else-if="lista.erro" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">{{ lista.erro }}</p>
        <p v-else-if="!lista.itens.length" class="py-6 text-center text-sm text-gray-500">
            Lista não disponível para esta rodada (rodadas anteriores a 02/10 não guardavam o detalhe).
        </p>
        <ul v-else class="max-h-[60vh] divide-y divide-gray-100 overflow-y-auto">
            <li v-for="(item, i) in lista.itens" :key="i" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-1.5 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-medium text-gray-800" :title="item.nome">{{ item.nome }}</p>
                    <p v-if="item.cnpj" class="text-[0.7rem] tabular-nums text-gray-500">{{ item.cnpj }}</p>
                </div>
                <p v-if="item.info" class="text-xs text-gray-500 sm:text-right">{{ item.info }}</p>
            </li>
        </ul>
    </ModalPadrao>
</template>
