<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    frescor: { type: Array, default: () => [] },
    relatorios: { type: Object, default: () => ({ itens: [], erro: null }) },
    rodadas: { type: Array, default: () => [] },
    emAndamento: { type: Boolean, default: false },
    aviso: { type: Object, default: () => ({}) },
});

/*
 * ⚠️ A tela se recarrega sozinha ENQUANTO uma rodada está em andamento, e só nesse caso.
 * A corrente leva ~2 min; sem isso o usuário clicaria em "Atualizar agora", não veria
 * nada mudar e clicaria de novo.
 *
 * `only:` limita o payload às props que mudam — o inventário do S3 fica de fora porque
 * não muda durante uma rodada e é a única que custa uma chamada de rede.
 */
const POLL_MS = 4000;
let timer = null;

function pararPoll() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function ajustarPoll(ativo) {
    pararPoll();
    if (!ativo) return;

    timer = setInterval(() => {
        router.reload({ only: ['rodadas', 'emAndamento', 'frescor'] });
    }, POLL_MS);
}

watch(() => props.emAndamento, ajustarPoll, { immediate: true });
onBeforeUnmount(pararPoll);

const enviando = ref(false);

function atualizar(forcar = false) {
    if (forcar && !confirm(
        'Reimportar tudo mesmo sem relatório novo?\n\n'
        + 'Reescreve ~600 mil linhas de itens de pedido sem necessidade. '
        + 'Use só se desconfiar que uma importação anterior ficou pela metade.',
    )) return;

    enviando.value = true;
    router.post(route('atualizacoes.disparar'), { forcar }, {
        preserveScroll: true,
        onFinish: () => { enviando.value = false; },
    });
}

const TONS_STATUS = {
    sucesso: 'ok',
    sem_mudanca: 'neutral',
    executando: 'warn',
    travada: 'danger',
    falha: 'danger',
};

const ROTULOS_STATUS = {
    sucesso: 'Importou',
    sem_mudanca: 'Nada novo',
    executando: 'Rodando',
    travada: 'Interrompida',
    falha: 'Falhou',
};

// Verde até 2 dias, âmbar até 5, vermelho acima. O relatório é gerado à mão, então um dia
// de atraso é rotina e não merece alarme; uma semana já significa que alguém parou de
// subir arquivo.
function tomFrescor(dias) {
    if (dias === null || dias === undefined) return 'danger';
    if (dias <= 2) return 'ok';
    if (dias <= 5) return 'warn';
    return 'danger';
}

function textoDias(dias) {
    if (dias === null || dias === undefined) return 'sem dado';
    if (dias === 0) return 'hoje';
    if (dias === 1) return 'ontem';
    return `há ${dias} dias`;
}

const ultima = computed(() => props.rodadas[0] ?? null);

const expandida = ref(null);
function alternar(id) {
    expandida.value = expandida.value === id ? null : id;
}

const fmtData = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

function dataHora(iso) {
    return iso ? fmtData.format(new Date(iso)) : '—';
}

function dataCurta(valor) {
    if (!valor) return '—';
    // Vem como 'YYYY-MM-DD' do banco; montar Date com a string crua interpretaria UTC e
    // poderia voltar um dia no fuso de São Paulo.
    const [a, m, d] = valor.split('-');
    return `${d}/${m}/${a}`;
}

function tamanho(bytes) {
    if (!bytes && bytes !== 0) return '—';
    const mb = bytes / 1024 / 1024;
    return mb >= 1
        ? `${mb.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`
        : `${(bytes / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 0 })} KB`;
}

function duracao(segundos) {
    if (segundos === null || segundos === undefined) return '—';
    if (segundos < 60) return `${segundos.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}s`;
    const min = Math.floor(segundos / 60);
    return `${min}m ${Math.round(segundos - min * 60)}s`;
}

const numero = (n) => (n ?? 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="Atualização de dados" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Atualização de dados">
                <template #icon>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 9A8 8 0 0 0 5.6 5.6L4 9m0 6a8 8 0 0 0 14.4 3.4L20 15" />
                    </svg>
                </template>
                <template #subtitle>
                    Os relatórios que você envia com <code class="rounded bg-white/10 px-1">enviar-relatorios-totvs.sh</code>
                    chegam ao S3 e são importados de hora em hora. Use o botão só quando não quiser esperar.
                </template>
                <template #meta>
                    <StatusPill v-if="ultima" :tone="TONS_STATUS[ultima.status]" surface="dark">
                        Última: {{ ROTULOS_STATUS[ultima.status] }} — {{ dataHora(ultima.iniciada_em) }}
                    </StatusPill>
                    <StatusPill v-else tone="neutral" surface="dark">Nenhuma rodada registrada</StatusPill>
                </template>
            </PageHero>

            <div
                v-if="aviso.sucesso || aviso.erro"
                class="mb-4 rounded border px-4 py-2.5 text-sm"
                :class="aviso.erro
                    ? 'border-red-300 bg-red-50 text-red-700'
                    : 'border-emerald-300 bg-emerald-50 text-emerald-700'"
            >
                {{ aviso.erro || aviso.sucesso }}
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- Frescor -->
                <div class="lg:col-span-1">
                    <DarkCard title="Idade do dado" subtitle="Data mais recente em cada tabela">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" d="M12 7v5l3 2" />
                            </svg>
                        </template>

                        <div class="space-y-3">
                            <div v-for="f in frescor" :key="f.tabela">
                                <div class="mb-1 flex items-baseline justify-between gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        {{ f.dominio }}
                                    </span>
                                    <span class="text-[0.65rem] text-gray-400">rel. {{ f.relatorio }}</span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <KpiTile :value="dataCurta(f.data)" label="Mais recente" :tone="tomFrescor(f.dias)" />
                                    <KpiTile :value="textoDias(f.dias)" label="Atraso" :tone="tomFrescor(f.dias)" />
                                    <KpiTile :value="numero(f.linhas)" label="Linhas" compact />
                                </div>
                            </div>

                            <p class="border-t border-gray-200 pt-3 text-xs leading-relaxed text-gray-500">
                                O sistema não fala com o TOTVS: ele só lê o que você exporta. Se a data
                                acima não avança, o relatório correspondente não foi regerado.
                            </p>
                        </div>
                    </DarkCard>
                </div>

                <!-- Ação + histórico -->
                <div class="lg:col-span-2">
                    <DarkCard title="Rodadas" subtitle="Automática de hora em hora; manual pelo botão">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10" />
                            </svg>
                        </template>
                        <template #actions>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded border border-cyan bg-cyan/10 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-cyan/20 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="enviando || emAndamento"
                                @click="atualizar(false)"
                            >
                                <svg class="h-3.5 w-3.5" :class="emAndamento ? 'animate-spin' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 9A8 8 0 0 0 5.6 5.6L4 9m0 6a8 8 0 0 0 14.4 3.4L20 15" />
                                </svg>
                                {{ emAndamento ? 'Rodando...' : 'Atualizar agora' }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded border border-white/25 px-2.5 py-1 text-xs font-semibold text-gray-300 transition hover:border-amber hover:text-amber disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="enviando || emAndamento"
                                title="Reimporta mesmo sem relatório novo"
                                @click="atualizar(true)"
                            >
                                Forçar
                            </button>
                        </template>

                        <p v-if="emAndamento" class="mb-3 rounded border border-amber/40 bg-amber/10 px-3 py-2 text-xs text-gray-700">
                            Importação em andamento — esta tela se atualiza sozinha. Leva cerca de 2 minutos.
                        </p>

                        <div v-if="!rodadas.length" class="py-6 text-center text-sm text-gray-500">
                            Nenhuma rodada registrada ainda.
                        </div>

                        <div v-else class="tbl-wrap">
                            <table class="tbl min-w-[640px]">
                                <thead>
                                    <tr class="tbl-head-row">
                                        <th class="tbl-th">Quando</th>
                                        <th class="tbl-th">Status</th>
                                        <th class="tbl-th">Origem</th>
                                        <th class="tbl-th">Duração</th>
                                        <th class="tbl-th">Detalhes</th>
                                    </tr>
                                </thead>
                                <tbody class="tbl-body">
                                    <template v-for="r in rodadas" :key="r.id">
                                        <tr class="tbl-row">
                                            <td class="tbl-td whitespace-nowrap">{{ dataHora(r.iniciada_em) }}</td>
                                            <td class="tbl-td">
                                                <StatusPill :tone="TONS_STATUS[r.status]" size="sm">
                                                    {{ ROTULOS_STATUS[r.status] }}
                                                </StatusPill>
                                            </td>
                                            <td class="tbl-td">
                                                <span class="tbl-main">{{ r.origem === 'manual' ? 'Manual' : 'Automática' }}</span>
                                                <span v-if="r.quem" class="tbl-sub">{{ r.quem }}</span>
                                            </td>
                                            <td class="tbl-td whitespace-nowrap">{{ duracao(r.duracao) }}</td>
                                            <td class="tbl-td">
                                                <div class="tbl-acoes">
                                                    <button
                                                        v-if="r.passos.length || r.erro"
                                                        type="button"
                                                        class="tbl-acao tbl-acao-neutro"
                                                        :title="expandida === r.id ? 'Recolher' : 'Ver o que rodou'"
                                                        @click="alternar(r.id)"
                                                    >
                                                        <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" :d="expandida === r.id ? 'M18 15l-6-6-6 6' : 'M6 9l6 6 6-6'" />
                                                        </svg>
                                                    </button>
                                                    <span v-else class="text-[0.65rem] text-gray-400">—</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr v-if="expandida === r.id" class="tbl-row">
                                            <td colspan="5" class="bg-gray-50 px-3 py-3 text-left align-top">
                                                <p v-if="r.erro" class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                                                    {{ r.erro }}
                                                </p>
                                                <div v-for="p in r.passos" :key="p.comando" class="mb-2 last:mb-0">
                                                    <div class="flex items-baseline gap-2">
                                                        <span class="font-mono text-xs font-semibold" :class="p.falhou ? 'text-red-600' : 'text-navy'">
                                                            {{ p.comando }}
                                                        </span>
                                                        <span class="text-[0.65rem] text-gray-400">{{ p.segundos }}s</span>
                                                    </div>
                                                    <pre class="mt-1 max-h-52 overflow-auto whitespace-pre-wrap break-words rounded border border-gray-200 bg-white px-2 py-1.5 font-mono text-[0.65rem] leading-relaxed text-gray-600">{{ p.saida || '(sem saída)' }}</pre>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </DarkCard>
                </div>

                <!-- Inventário do S3 -->
                <div class="lg:col-span-3">
                    <DarkCard title="Relatórios enviados" subtitle="O que está hoje no S3, esperando importação">
                        <template #icon>
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3 12 9 4.5L21 12M3 16.5 12 21l9-4.5" />
                            </svg>
                        </template>

                        <p v-if="relatorios.erro" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
                            Não consegui listar o S3: {{ relatorios.erro }}
                        </p>

                        <div v-else-if="!relatorios.itens.length" class="py-6 text-center text-sm text-gray-500">
                            Nenhum relatório no S3. Rode <code class="rounded bg-gray-100 px-1">bash infra/enviar-relatorios-totvs.sh</code> na sua máquina.
                        </div>

                        <div v-else class="tbl-wrap">
                            <table class="tbl min-w-[560px]">
                                <thead>
                                    <tr class="tbl-head-row">
                                        <th class="tbl-th">Arquivo</th>
                                        <th class="tbl-th">Tamanho</th>
                                        <th class="tbl-th">Enviado em</th>
                                    </tr>
                                </thead>
                                <tbody class="tbl-body">
                                    <tr v-for="a in relatorios.itens" :key="a.caminho" class="tbl-row">
                                        <td class="tbl-td">
                                            <span class="tbl-main max-w-[420px]">{{ a.caminho }}</span>
                                        </td>
                                        <td class="tbl-td whitespace-nowrap">{{ tamanho(a.bytes) }}</td>
                                        <td class="tbl-td whitespace-nowrap">{{ dataHora(a.enviado_em) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </DarkCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
