<script setup>
/**
 * Visão Diretor → Maiores redes.
 *
 * Das maiores redes do país (planilha da diretoria 2026, uma aba por categoria), em quais a
 * Autopel atua. Status de rede é só Ativo / A trabalhar (Tony, 2026-10-16). A linha da rede
 * abre e mostra os clientes e leads com responsável, última compra e status — e é ali que
 * se vincula ou tira um cliente à mão.
 *
 * ⚠️ Não é a Maiores por Segmento: aquela lista NOSSOS clientes e leads por segmento do
 * TOTVS; esta lista as redes do MERCADO. Ver docs/visao-diretor.md, "Maiores redes".
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import ResumoRedesCard from '@/Components/VisaoDiretor/ResumoRedesCard.vue';
import RedesTabela from '@/Components/VisaoDiretor/RedesTabela.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import { formatInteiro } from '@/utils/formato';

const props = defineProps({
    resumo: { type: Array, default: () => [] },
    lista: { type: Object, default: null },
    filtros: { type: Object, required: true },
});

const filtros = reactive({
    categoria: props.filtros.categoria || '',
    status: props.filtros.status || '',
    busca: props.filtros.busca || '',
    receita: props.filtros.receita || '',
});

const carregando = ref(false);

function visitar() {
    router.get(route('visao-diretor.redes.index'), {
        categoria: filtros.categoria || undefined,
        status: filtros.status || undefined,
        busca: filtros.busca || undefined,
        receita: filtros.receita || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['lista', 'filtros'],
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
}

let atrasoBusca;
function onBusca() {
    clearTimeout(atrasoBusca);
    atrasoBusca = setTimeout(visitar, 300);
}

function trocarAba(categoria) {
    Object.assign(filtros, { categoria, status: '', busca: '', receita: '' });
    visitar();
}

// Os tiles SÃO o filtro de status: clicar aplica, clicar de novo remove.
const TILES = [
    { status: 'ativo', tom: 'ok', rotulo: 'Ativas' },
    { status: 'a_trabalhar', tom: 'warn', rotulo: 'A trabalhar' },
];

function filtrarStatus(status) {
    filtros.status = filtros.status === status ? '' : status;
    visitar();
}

// "CNPJ irregular": a rede ou um cliente nosso dela com CNPJ não ativo na Receita.
function filtrarReceita() {
    filtros.receita = filtros.receita ? '' : 'irregular';
    visitar();
}

const categoriaAberta = computed(() => props.resumo.find((c) => c.categoria === filtros.categoria) ?? null);

const tabela = computed(() => (filtros.categoria && props.lista?.categoria === filtros.categoria ? props.lista : null));

/*
 * Vincular/remover devolve os contadores novos da rede: aplicados na linha e nos tiles
 * sem recarregar a lista (a linha aberta continua aberta).
 */
function atualizouRede(n) {
    const linha = tabela.value?.linhas.find((l) => l.id === n.id);
    if (! linha) return;

    const antes = linha.status;
    Object.assign(linha, n);
    // A correção do CNPJ devolve só os campos da Receita, sem status.
    if (n.status && antes !== n.status) {
        const c = tabela.value.contagens;
        c[antes] -= 1;
        c[n.status] += 1;
        const r = categoriaAberta.value;
        if (r) { r[antes] -= 1; r[n.status] += 1; }
    }
}

// A aba (categoria) é estrutural: não conta como filtro para o aviso do Excel.
const temFiltrosAtivos = computed(() => Boolean(filtros.status || filtros.busca || filtros.receita));

const totalAtivas = computed(() => props.resumo.reduce((s, c) => s + c.ativo, 0));
const totalRedes = computed(() => props.resumo.reduce((s, c) => s + c.total, 0));
</script>

<template>
    <Head title="Maiores redes" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Maiores redes">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M3.6 8.6 5 3.8h14l1.4 4.8" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M3.6 8.6c0 1.5 1.2 2.6 2.8 2.6s2.8-1.1 2.8-2.6c0 1.5 1.2 2.6 2.8 2.6s2.8-1.1 2.8-2.6c0 1.5 1.2 2.6 2.8 2.6s2.8-1.1 2.8-2.6" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M5 11v9.2h14V11M10 20.2v-5h4v5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    As maiores redes do Brasil por categoria e em quais a Autopel atua.
                </template>
                <template v-if="filtros.categoria" #filtrosFixos>
                    <div class="flex w-full flex-col gap-1 sm:min-w-[180px] sm:max-w-[260px] sm:flex-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="filtros.busca"
                            type="search"
                            placeholder="Rede ou vendedor"
                            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                            @input="onBusca"
                        />
                    </div>
                </template>
            </PageHero>

            <!-- Resumo + uma aba por categoria, na ordem da planilha da diretoria. -->
            <div class="flex gap-1 overflow-x-auto border-b border-gray-300" role="tablist" aria-label="Categorias">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="! filtros.categoria"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="! filtros.categoria ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba('')"
                >Resumo</button>
                <button
                    v-for="c in resumo"
                    :key="c.categoria"
                    type="button"
                    role="tab"
                    :aria-selected="filtros.categoria === c.categoria"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium uppercase tracking-wide transition"
                    :class="filtros.categoria === c.categoria ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba(c.categoria)"
                >{{ c.nome }}</button>
            </div>

            <ResumoRedesCard
                v-if="! filtros.categoria"
                :linhas="resumo"
                :subtitulo="`${formatInteiro(totalAtivas)} de ${formatInteiro(totalRedes)} redes com cliente nosso ativo · clique para abrir a categoria`"
                @abrir="trocarAba"
            >
                <template #actions>
                    <ExportarExcelButton rota="visao-diretor.redes.exportar" :filtros="{}" :tem-filtros-ativos="false" />
                </template>
            </ResumoRedesCard>

            <template v-else>
                <!-- Contam a categoria com a busca aplicada, ignorando o próprio filtro de status. -->
                <div class="flex flex-wrap gap-2">
                    <KpiTile :value="formatInteiro(tabela?.contagens.total ?? 0)" label="Todas" botao @click="filtrarStatus('')" />
                    <KpiTile
                        v-for="t in TILES"
                        :key="t.status"
                        :value="formatInteiro(tabela?.contagens[t.status] ?? 0)"
                        :label="t.rotulo"
                        :tone="t.tom"
                        botao
                        :ativo="filtros.status === t.status"
                        @click="filtrarStatus(t.status)"
                    />
                    <KpiTile
                        :value="formatInteiro(tabela?.contagens.irregular ?? 0)"
                        label="CNPJ irregular"
                        tone="danger"
                        botao
                        :ativo="filtros.receita === 'irregular'"
                        title="A rede ou um cliente nosso dela com CNPJ baixado, inapto ou suspenso na Receita"
                        @click="filtrarReceita"
                    />
                </div>

                <DarkCard
                    :title="categoriaAberta?.nome ?? ''"
                    subtitle="Maiores primeiro · * número de lojas estimado ou sem dado novo · clique na rede para ver os clientes"
                >
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 20V9l8-5 8 5v11" stroke-linejoin="round" />
                            <path d="M9 20v-6h6v6" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <ExportarExcelButton rota="visao-diretor.redes.exportar" :filtros="filtros" :tem-filtros-ativos="temFiltrosAtivos" />
                    </template>

                    <p v-if="! tabela" class="px-3 py-10 text-center text-sm text-gray-400">Carregando…</p>
                    <p v-else-if="! tabela.linhas.length" class="px-3 py-10 text-center text-sm text-gray-400">Nenhuma rede com os filtros aplicados.</p>
                    <div v-else class="transition-opacity" :class="carregando ? 'opacity-50' : ''">
                        <RedesTabela :linhas="tabela.linhas" @atualizou="atualizouRede" />
                    </div>
                </DarkCard>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
