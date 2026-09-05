<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import EscopoVazioAviso from '@/Components/EscopoVazioAviso.vue';
import CarteiraSegmentoCard from '@/Components/Dashboard/CarteiraSegmentoCard.vue';
import CarteiraTabela from '@/Components/Carteira/CarteiraTabela.vue';
import CalendarioAgendamentos from '@/Components/Carteira/CalendarioAgendamentos.vue';
import MotivoInatividadeModal from '@/Components/Carteira/MotivoInatividadeModal.vue';
import ObservacoesModal from '@/Components/Observacoes/ObservacoesModal.vue';
import AgendarLigacaoModal from '@/Components/Carteira/AgendarLigacaoModal.vue';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';

const props = defineProps({
    role: String,
    aba: { type: String, default: 'clientes' },
    clientes: Object,
    kpis: Object,
    agendamentos: { type: Array, default: () => [] },
    filtros: Object,
    opcoes: Object,
    visao: Object,
});

// "Ver detalhes" é liberado pra todos os perfis (decisão do Tony, 2026-08-10) — o
// escopo já é garantido no servidor por `CarteiraController::autorizarCliente()`,
// então o vendedor só alcança cliente da própria carteira.
/**
 * Quem pode OPERAR o cliente (ligar, agendar, orçar) — não é a mesma pergunta de quem
 * pode VER.
 *
 * ⚠️ Inclui o supervisor em modo "Minha carteira": na Autopel supervisor também vende, e
 * a lista que ele vê nesse modo é a carteira PESSOAL dele. Deixar os botões de fora ali
 * seria mostrar os clientes dele e proibi-lo de trabalhá-los.
 *
 * Em modo Equipe os botões somem de novo — os clientes na tela são de outras pessoas, e
 * registrar contato no lugar do vendedor sujaria a métrica de atividade dele.
 */
const page = usePage();

const podeOperar = computed(
    () => ['vendedor', 'representante'].includes(props.role)
        || (props.role === 'supervisor' && page.props.modoVisao?.modo === 'pessoal'),
);

const filtros = reactive({
    busca: props.filtros.busca || '',
    estado: props.filtros.estado || '',
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    aderencia: props.filtros.aderencia || '',
    // Vem do card de Potencial da Carteira do Painel; não tem campo próprio na barra de
    // filtros — é anunciado por uma faixa acima da tabela, com "limpar".
    sem_familia: props.filtros.semFamilia || '',
    ordenar: props.filtros.ordenar || 'nome_asc',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function paramsComAba(aba = props.aba) {
    return { ...filtros, aba };
}

// `agendamentos` é uma prop opcional no servidor (Inertia::optional): só vem quando
// pedida explicitamente no `only`. Filtrar mexe na lista de clientes, não na agenda —
// então NÃO pedimos agendamentos aqui, e a consulta deixa de rodar à toa.
function aplicarFiltros() {
    router.get(route('carteira.index'), paramsComAba('clientes'), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['clientes', 'kpis', 'filtros', 'visao', 'aba'],
    });
}

function trocarAba(aba) {
    const apenas = ['clientes', 'kpis', 'filtros', 'visao', 'aba'];

    // Só a aba Calendário paga pelos agendamentos.
    if (aba === 'calendario') apenas.push('agendamentos');

    router.get(route('carteira.index'), paramsComAba(aba), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: apenas,
    });
}

// Entrar direto por URL (/carteira?aba=calendario) é uma visita completa, e visita
// completa não traz prop opcional — sem isto o calendário abriria vazio.
onMounted(() => {
    if (props.aba === 'calendario' && !props.agendamentos.length) {
        router.reload({ only: ['agendamentos'], preserveState: true, preserveScroll: true });
    }
});

// Substituiu o select "Ordenar por" — a ordenação agora é o clique no header da
// coluna. Volta pra página 1 de propósito: manter o offset ao reordenar deixa o
// usuário no meio de uma lista que não é mais a mesma.
function ordenarPor(valor) {
    filtros.ordenar = valor;
    aplicarFiltros();
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', estado: '', segmento: '', status: '', aderencia: '',
        ordenar: 'nome_asc', visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

const modalMotivo = ref(false);
const modalObservacao = ref(false);
const modalAgendamento = ref(false);
const clienteAtivo = ref(null);

function abrirMotivo(cliente) {
    clienteAtivo.value = cliente;
    modalMotivo.value = true;
}

function abrirObservacao(cliente) {
    clienteAtivo.value = cliente;
    modalObservacao.value = true;
}

function abrirAgendamento(cliente) {
    clienteAtivo.value = cliente;
    modalAgendamento.value = true;
}

const temFiltrosAtivos = computed(() =>
    ['busca', 'estado', 'segmento', 'status', 'aderencia', 'sem_familia'].some((k) => filtros[k] !== '')
    || !!filtros.visao_supervisor
    || !!filtros.visao_vendedor,
);

function limparSemFamilia() {
    filtros.sem_familia = '';
    aplicarFiltros();
}
</script>

<template>
    <Head title="Carteira" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Carteira">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="6" width="18" height="14" rx="1" />
                            <path d="M3 10h18M8 6V4h8v2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        {{ kpis.total }} cliente{{ kpis.total !== 1 ? 's' : '' }} · {{ kpis.pctDentro }}% no segmento
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[280px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Razão social, CNPJ ou código..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField label="Estado" :model-value="filtros.estado" @update:model-value="(v) => { filtros.estado = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="e in opcoes.estados" :key="e" :value="e">{{ e }}</option>
                        </FilterField>

                        <FilterField label="Segmento" :model-value="filtros.segmento" @update:model-value="(v) => { filtros.segmento = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="s in opcoes.segmentos" :key="s.codigo" :value="s.codigo">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option value="ativo">Ativo</option>
                            <option value="inativando">Inativando</option>
                            <option value="inativo">Inativo</option>
                        </FilterField>

                        <FilterField label="Aderência" :model-value="filtros.aderencia" @update:model-value="(v) => { filtros.aderencia = v; aplicarFiltros(); }">
                            <option value="">Todas</option>
                            <option value="dentro">No segmento</option>
                            <option value="fora">Fora do segmento</option>
                            <option value="sem_segmento">Sem segmento definido</option>
                        </FilterField>

                        <FilterField v-if="visao.supervisores.length" label="Supervisão" :model-value="filtros.visao_supervisor" @update:model-value="(v) => { filtros.visao_supervisor = v; filtros.visao_vendedor = ''; aplicarFiltros(); }">
                            <option value="">Todas as Equipes</option>
                            <option v-for="s in visao.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">{{ s.nome }}</option>
                        </FilterField>

                        <FilterField v-if="visao.mostrarSeletor" label="Vendedor" :model-value="filtros.visao_vendedor" @update:model-value="(v) => { filtros.visao_vendedor = v; aplicarFiltros(); }">
                            <option value="">Todos os Vendedores</option>
                            <option v-for="v in visao.vendedores" :key="v.cod_vendedor" :value="v.cod_vendedor">{{ v.nome }}</option>
                        </FilterField>

                        <button type="button" class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100" @click="limparFiltros">
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <EscopoVazioAviso :total="kpis.total" recurso="cliente" />
                <CarteiraSegmentoCard :carteira-segmento="kpis" :base-filtros="filtros" />

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                        :class="aba === 'clientes' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="trocarAba('clientes')"
                    >
                        Clientes
                    </button>
                    <button
                        type="button"
                        class="rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                        :class="aba === 'calendario' ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="trocarAba('calendario')"
                    >
                        Calendário
                    </button>
                </div>

                <template v-if="aba === 'clientes'">
                    <!--
                        Recorte vindo do card de Potencial da Carteira do Painel. Precisa ser
                        anunciado: sem isso a pessoa chega numa lista bem menor que a carteira
                        dela, sem campo na barra de filtros explicando o porquê, e conclui que
                        a tela quebrou.
                    -->
                    <div
                        v-if="filtros.sem_familia"
                        class="flex flex-wrap items-center justify-between gap-2 rounded border border-cyan/40 bg-cyan/10 px-3 py-2"
                    >
                        <p class="text-sm text-gray-700">
                            Mostrando apenas clientes que compraram nos últimos 12 meses e
                            <strong class="font-semibold">ainda não compram {{ props.filtros.semFamiliaRotulo }}</strong>.
                            <!--
                                ⚠️ Os dois números lado a lado de propósito: o card do Painel
                                conta EMPRESAS e esta tabela lista FILIAIS, porque a nota
                                fiscal não registra a loja. Sem dizer isso aqui, quem clica
                                em "40" e encontra 86 conclui que o filtro está errado.
                            -->
                            <span v-if="props.filtros.semFamiliaEmpresas" class="text-gray-500">
                                São {{ props.filtros.semFamiliaEmpresas }}
                                {{ props.filtros.semFamiliaEmpresas === 1 ? 'empresa' : 'empresas' }},
                                listadas abaixo por filial.
                            </span>
                        </p>
                        <button
                            type="button"
                            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
                            @click="limparSemFamilia"
                        >
                            Limpar recorte
                        </button>
                    </div>

                    <DarkCard title="Carteira de Clientes" :subtitle="`${kpis.total} cliente${kpis.total !== 1 ? 's' : ''} no escopo atual`">
                        <template #icon>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                            </svg>
                        </template>
                        <template #actions>
                            <!-- assincrono: a carteira completa leva ~95s, mais que o idle
                                 timeout do ALB. Gera em fila e avisa no sino. -->
                            <ExportarExcelButton
                                rota="carteira.exportar"
                                :filtros="filtros"
                                :tem-filtros-ativos="temFiltrosAtivos"
                                assincrono
                            />
                        </template>

                        <CarteiraTabela
                            v-if="clientes.data.length"
                            :clientes="clientes.data"
                            :pode-ver-detalhes="true"
                            :pode-ligar="podeOperar"
                            :pode-agendar="podeOperar"
                            :pode-orcamento="podeOperar"
                            :pode-observar="true"
                            :ordenar="filtros.ordenar"
                            @ordenar="ordenarPor"
                            @motivo-inatividade="abrirMotivo"
                            @observacao="abrirObservacao"
                            @agendar-ligacao="abrirAgendamento"
                        />
                        <p v-else class="text-sm text-gray-400">Nenhum cliente encontrado com os filtros atuais.</p>

                        <div class="mt-4">
                            <Pagination :meta="clientes" :only="['clientes']" />
                        </div>
                    </DarkCard>
                </template>

                <CalendarioAgendamentos v-else :agendamentos="agendamentos" />
            </div>
        </div>

        <MotivoInatividadeModal :show="modalMotivo" :cliente="clienteAtivo" @close="modalMotivo = false" />
        <ObservacoesModal
            :show="modalObservacao"
            :subtitulo="clienteAtivo ? `${clienteAtivo.razaoSocial} · ${clienteAtivo.cnpj || 'CNPJ não cadastrado'}` : ''"
            :historico-url="clienteAtivo ? route('observacoes.porCliente', clienteAtivo.id) : null"
            :payload="clienteAtivo ? { cliente_id: clienteAtivo.id, cnpj: clienteAtivo.cnpj || undefined } : {}"
            @close="modalObservacao = false"
        />
        <AgendarLigacaoModal :show="modalAgendamento" :cliente="clienteAtivo" @close="modalAgendamento = false" />
    </AuthenticatedLayout>
</template>
