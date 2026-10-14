<script setup>
/**
 * Visão Diretor → Maiores por Segmento.
 *
 * Para o dono da empresa ver, por segmento, onde a Autopel atua (cliente ativo), deixou de
 * atuar (perdendo / a trabalhar) e nunca atuou (lead). Redesenhada em 2026-10-14: redes,
 * contas e vínculos saíram — a tabela é cliente e lead, o mesmo grão da Carteira (ver
 * docs/visao-diretor.md e `UniversoDoSegmento`).
 *
 * O Resumo é uma linha por segmento; cada aba é uma tabela paginada que vem do servidor
 * (`universo`), só a do segmento aberto.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';
import FilterField from '@/Components/FilterField.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import Pagination from '@/Components/Pagination.vue';
import ResumoSegmentosCard from '@/Components/VisaoDiretor/ResumoSegmentosCard.vue';
import ClientesLeadsTabela from '@/Components/VisaoDiretor/ClientesLeadsTabela.vue';
import EspecialistaSelo from '@/Components/Segmentos/EspecialistaSelo.vue';
import { ROTULOS_STATUS_CONTA } from '@/constants/visaoDiretor';
import { contarFiltrosAtivos } from '@/utils/filtros';
import { dataHora, formatInteiro } from '@/utils/formato';
import { urlDoMapa } from '@/utils/mapas';
import CartaoCnpjModal from '@/Components/Receita/CartaoCnpjModal.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';

const props = defineProps({
    resumo: { type: Array, default: () => [] },
    universo: { type: Object, default: null },
    ufs: { type: Array, default: () => [] },
    reconstruidoEm: { type: String, default: null },
    filtros: { type: Object, required: true },
});

const filtros = reactive({
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    uf: props.filtros.uf || '',
    busca: props.filtros.busca || '',
});

// Sem `pagina`: mudar de aba ou de filtro sempre volta para a primeira página.
function queryDaPagina() {
    return {
        segmento: filtros.segmento || undefined,
        status: filtros.status || undefined,
        uf: filtros.uf || undefined,
        busca: filtros.busca || undefined,
    };
}

// Enquanto a tabela do segmento vem do servidor, ela fica esmaecida.
const carregando = ref(false);

function visitar() {
    router.get(route('visao-diretor.maiores.index'), queryDaPagina(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['universo', 'filtros'],
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
}

let atrasoBusca;
function onBusca() {
    clearTimeout(atrasoBusca);
    atrasoBusca = setTimeout(visitar, 300);
}

/** Trocar de aba limpa os filtros: cada segmento abre inteiro. */
function trocarAba(codigo) {
    Object.assign(filtros, { segmento: codigo, status: '', uf: '', busca: '' });
    visitar();
}

// Os tiles SÃO o filtro de status: clicar aplica, clicar de novo remove.
const TILES = [
    { status: 'ativo', tom: 'ok', rotulo: 'Ativos' },
    { status: 'inativando', tom: 'warn', rotulo: ROTULOS_STATUS_CONTA.inativando },
    { status: 'inativo', tom: 'danger', rotulo: ROTULOS_STATUS_CONTA.inativo },
    { status: 'lead', tom: 'default', rotulo: 'Leads' },
];

function filtrarStatus(status) {
    filtros.status = filtros.status === status ? '' : status;
    visitar();
}

const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, ['status', 'uf']));

const segmentoAberto = computed(() => props.resumo.find((s) => s.codigo === filtros.segmento) ?? null);

// A tabela chegou e é deste segmento (durante a troca de aba ainda é a anterior).
const tabela = computed(() => (filtros.segmento && props.universo?.segmento === filtros.segmento ? props.universo : null));

const atualizada = computed(() => (props.reconstruidoEm ? `Atualizada ${dataHora(props.reconstruidoEm)}` : ''));

const subtituloTabela = computed(() => {
    const c = tabela.value?.contagens;
    if (! c) return 'Carregando…';

    return [
        `${formatInteiro(c.clientes)} ${c.clientes === 1 ? 'cliente' : 'clientes'}`,
        `${formatInteiro(c.leads)} ${c.leads === 1 ? 'lead' : 'leads'}`,
        'maiores primeiro',
        atualizada.value,
    ].filter(Boolean).join(' · ');
});

// ─── Cartão CNPJ e mapa (endereço da Receita) ─────────────────────────────────

/*
 * As duas ações leem o mesmo cartão: o do cliente pela âncora (matriz) na rota da
 * Carteira, que compara com o TOTVS; o do lead pela rota dos Leads.
 */
function urlDoCartao(l) {
    return l.tipo === 'cliente' ? route('carteira.cartaoCnpj', l.clienteId) : route('leads.cartaoCnpj', l.leadId);
}

const cartaoDe = ref(null);

function abrirCartao(l) {
    cartaoDe.value = { url: urlDoCartao(l), cliente: { razaoSocial: l.nome, cnpj: l.documento } };
}

const avisoMapa = ref('');

/**
 * O endereço da Receita não fica na base mensal, só no cartão (consultado na hora e
 * guardado 30 dias). Então o mapa busca o cartão e abre o Maps com o endereço dele.
 *
 * ⚠️ A aba é aberta ANTES do fetch, ainda dentro do clique: aberta depois de um `await`,
 * o bloqueador de pop-up do navegador a barra. Sem endereço, a aba é fechada e a tela avisa.
 */
async function abrirMapa(l) {
    const aba = window.open('about:blank', '_blank');

    try {
        const resposta = await fetch(urlDoCartao(l), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const corpo = await resposta.json();
        const endereco = corpo.cartao?.endereco;
        const url = resposta.ok && endereco ? urlDoMapa({ ...endereco, documento: l.documento }) : null;

        if (! url) {
            aba?.close();
            avisoMapa.value = corpo.erro || 'A Receita não tem um endereço completo para este CNPJ.';

            return;
        }

        if (aba) {
            aba.opener = null;
            aba.location.href = url;
        } else {
            window.open(url, '_blank', 'noopener');
        }
    } catch {
        aba?.close();
        avisoMapa.value = 'Não foi possível consultar a Receita agora. Tente de novo em alguns minutos.';
    }
}
</script>

<template>
    <Head title="Maiores por Segmento" />

    <AuthenticatedLayout>
        <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Maiores por Segmento" :filtros-ativos="filtrosAtivos">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M3.6 3.8v16.6h16.8" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M6.8 15.6l3.9-4.4 3.2 2.8 5.3-6.2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M15.6 7.8h3.6v3.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    Onde a Autopel atua, deixou de atuar e ainda não atua, em cada segmento.
                </template>
                <template v-if="filtros.segmento" #filtrosFixos>
                    <div class="flex w-full flex-col gap-1 sm:min-w-[180px] sm:max-w-[260px] sm:flex-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Busca</label>
                        <input
                            v-model="filtros.busca"
                            type="search"
                            placeholder="Nome, razão social ou CNPJ"
                            class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                            @input="onBusca"
                        />
                    </div>
                </template>
                <template v-if="filtros.segmento" #filtros>
                    <FilterField v-model="filtros.uf" label="UF" @update:model-value="visitar">
                        <option value="">Todas</option>
                        <option v-for="uf in ufs" :key="uf" :value="uf">{{ uf }}</option>
                    </FilterField>
                </template>
            </PageHero>

            <!-- Uma aba por segmento, do maior para o menor, mais o Resumo. -->
            <div class="flex gap-1 overflow-x-auto border-b border-gray-300" role="tablist" aria-label="Segmentos">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="! filtros.segmento"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="! filtros.segmento ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba('')"
                >Resumo</button>
                <button
                    v-for="s in resumo"
                    :key="s.codigo"
                    type="button"
                    role="tab"
                    :aria-selected="filtros.segmento === s.codigo"
                    class="-mb-px shrink-0 border-b-2 px-4 py-2 text-sm font-medium uppercase tracking-wide transition"
                    :class="filtros.segmento === s.codigo ? 'border-cyan bg-white text-gray-900' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'"
                    @click="trocarAba(s.codigo)"
                >{{ s.nome }}</button>
            </div>

            <ResumoSegmentosCard
                v-if="! filtros.segmento"
                :linhas="resumo"
                :subtitulo="['Clientes por situação e leads. Clique para abrir o segmento.', atualizada].filter(Boolean).join(' · ')"
                @abrir="trocarAba"
            >
                <template #actions>
                    <ExportarExcelButton rota="visao-diretor.maiores.exportar" :filtros="{}" :tem-filtros-ativos="false" />
                </template>
            </ResumoSegmentosCard>

            <template v-else>
                <!-- Contam a tabela inteira com busca e UF, ignorando o próprio filtro de status. -->
                <div class="flex flex-wrap gap-2">
                    <KpiTile
                        :value="formatInteiro(tabela?.contagens.total ?? 0)"
                        label="Todos"
                        botao
                        @click="filtrarStatus('')"
                    />
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
                </div>

                <DarkCard :title="segmentoAberto?.nome ?? ''" :subtitle="subtituloTabela">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 20V9l8-5 8 5v11" stroke-linejoin="round" />
                            <path d="M9 20v-6h6v6" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <ExportarExcelButton
                            rota="visao-diretor.maiores.exportar-clientes"
                            :filtros="filtros"
                            :tem-filtros-ativos="filtrosAtivos > 0 || filtros.busca !== ''"
                        />
                        <!-- O especialista é marcado pela estrela em Equipe → Segmentos. -->
                        <Link
                            :href="route('equipe.segmentos')"
                            class="rounded px-1 py-0.5 transition hover:bg-white/10"
                            title="O especialista é marcado pela estrela em Equipe → Segmentos"
                        >
                            <EspecialistaSelo :especialista="segmentoAberto?.especialista" superficie="escuro" />
                        </Link>
                    </template>

                    <p v-if="! tabela" class="px-3 py-10 text-center text-sm text-gray-400">Carregando…</p>
                    <p v-else-if="! tabela.linhas.data.length" class="px-3 py-10 text-center text-sm text-gray-400">
                        {{ tabela.contagens.total ? 'Nada neste segmento com os filtros aplicados.' : 'Nenhum cliente ou lead neste segmento.' }}
                    </p>
                    <template v-else>
                        <div class="transition-opacity" :class="carregando ? 'opacity-50' : ''">
                            <ClientesLeadsTabela
                                :linhas="tabela.linhas.data"
                                :posicao-inicial="tabela.linhas.from ?? 1"
                                :segmento="tabela.segmento"
                                @cartao-cnpj="abrirCartao"
                                @mapa="abrirMapa"
                            />
                        </div>
                        <div class="mt-3"><Pagination :meta="tabela.linhas" :only="['universo']" /></div>
                    </template>
                </DarkCard>
            </template>
        </div>
        <CartaoCnpjModal
            :show="!! cartaoDe"
            :url="cartaoDe?.url ?? ''"
            :cliente="cartaoDe?.cliente ?? null"
            @close="cartaoDe = null"
        />

        <ModalPadrao :show="!! avisoMapa" titulo="Endereço da Receita" max-width="md" @close="avisoMapa = ''">
            <p class="text-sm text-gray-600">{{ avisoMapa }}</p>
        </ModalPadrao>
    </AuthenticatedLayout>
</template>
