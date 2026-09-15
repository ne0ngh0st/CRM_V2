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
import OrdenarMobile from '@/Components/Tabela/OrdenarMobile.vue';
import { ROTULOS_STATUS_CARTEIRA, ORDENACOES_CARTEIRA } from '@/constants/carteira';
import { contarFiltrosAtivos } from '@/utils/filtros';

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
    /*
     * Precisa viajar junto de todo filtro, ordenação e troca de aba: `paramsComAba()`
     * monta a query string a partir DESTE objeto, e o que não estiver aqui se perde na
     * próxima visita. Sem isto, filtrar por estado devolvia a pessoa para a lista por
     * filial sem nada explicar.
     */
    // Vazio = padrão (agrupado). Só o modo por filial precisa ser dito na URL.
    agrupar: props.filtros.agrupado ? '' : '0',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function paramsComAba(aba = props.aba) {
    return { ...filtros, aba };
}

/*
 * ⚠️ Nome próprio, e não `filtros.agrupado`: no template `filtros` é o objeto REACTIVE
 * LOCAL, cuja chave é `agrupar` (o nome do parâmetro da URL), enquanto quem diz se a
 * lista VEIO agrupada é `props.filtros.agrupado`, resposta do servidor. Passar
 * `filtros.agrupado` devolve undefined em silêncio — a tabela renderiza sem a coluna de
 * expansão, sem erro no console, e parece que a feature não foi aplicada. Foi
 * exatamente o que aconteceu na primeira versão. Mesma família da colisão
 * `modoVisao`/`visao` de 2026-09-03.
 */
const listaAgrupada = computed(() => !! props.filtros.agrupado);

/*
 * O status filtrado, para o subtítulo dizer "543 de 1.199 clientes · Inativo".
 *
 * ⚠️ Só no modo agrupado: por filial, `clientes.total` conta FILIAIS e "543 de 1.199
 * clientes" misturaria duas unidades na mesma frase. Naquele modo o subtítulo já diz as
 * duas contagens com o rótulo de cada uma, logo abaixo.
 *
 * ⚠️ O rótulo vem de `ROTULOS_STATUS_CARTEIRA`, nunca de um mapa local: o de `Detalhes.vue`
 * ficou para trás uma vez e a tela passou a exibir `pendente_totvs` cru (Regra de ouro nº 8).
 */
const statusFiltrado = computed(() => (listaAgrupada.value ? props.filtros.status || '' : ''));
const rotuloStatusFiltrado = computed(() => ROTULOS_STATUS_CARTEIRA[statusFiltrado.value] ?? statusFiltrado.value);

/**
 * "31.651 de 39.692 clientes" quando a lista é um recorte, "39.692 clientes" quando não é.
 *
 * ⚠️ Decide COMPARANDO os dois números, nunca perguntando quais filtros estão ativos: é o
 * que mantém a frase verdadeira quando um filtro novo entrar na tela sem ninguém lembrar
 * deste trecho. E mora aqui num lugar só porque o PageHero e o card da tabela dizem a
 * mesma coisa a 30cm de distância — divergirem é o defeito que esta rodada inteira
 * combate.
 */
const contagemDeClientes = computed(() => {
    const total = props.kpis.total;
    const rotulo = `${total} cliente${total !== 1 ? 's' : ''}`;

    return listaAgrupada.value && props.clientes.total !== total
        ? `${props.clientes.total} de ${rotulo}`
        : rotulo;
});

const subtituloDaTabela = computed(() => (
    listaAgrupada.value && props.clientes.total !== props.kpis.total
        ? contagemDeClientes.value
        : `${contagemDeClientes.value} no escopo atual`
));

/*
 * Troca a unidade da lista (cliente ↔ filial) e volta para a página 1: a paginação
 * de um modo não corresponde à do outro — a página 12 por filial não é a página 12
 * por cliente —, e manter o offset deixaria a pessoa no meio de uma lista que não é
 * mais a mesma. Mesmo raciocínio do `ordenarPor()`.
 */
function alternarAgrupamento() {
    filtros.agrupar = listaAgrupada.value ? '0' : '';
    aplicarFiltros();
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

// Atende os DOIS acessos à ordenação: o clique no header da coluna (desktop) e o seletor
// "Ordenar por" do celular, que existe porque abaixo de 640px a tabela vira cartão e o
// `<thead>` não está lá. Os dois emitem o mesmo `<campo>_<asc|desc>`.
// Volta pra página 1 de propósito: manter o offset ao reordenar deixa o usuário no meio de
// uma lista que não é mais a mesma.
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

/*
 * Uma lista de campos, dois consumidores: a contagem no botão "Filtros" do celular e o
 * aviso do Excel. Antes eram duas leituras da mesma pergunta em lugares diferentes.
 *
 * ⚠️ O BADGE CONTA SÓ O QUE O BOTÃO ESCONDE. A busca fica visível ao lado dele (slot
 * `#filtrosFixos`), então somá-la faria o botão dizer "1" com o modal de filtros
 * aparentemente vazio. O aviso do Excel é outra pergunta — "este arquivo sai recortado?"
 * — e aí a busca conta.
 */
const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, [
    'estado', 'segmento', 'status', 'aderencia', 'sem_familia',
    'visao_supervisor', 'visao_vendedor',
]));

const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '');

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
                <PageHero title="Carteira" :filtros-ativos="filtrosAtivos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="6" width="18" height="14" rx="1" />
                            <path d="M3 10h18M8 6V4h8v2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #subtitle><!--
                        ⚠️ Com um status filtrado, `kpis.total` volta a ser a CARTEIRA
                        INTEIRA (o card não aplica a si a faceta que desenha) enquanto a
                        lista mostra só o recorte. Dizer só "1.199 clientes" sobre uma
                        lista de 543 é o número que não bate; dizer "543 de 1.199" responde
                        as duas perguntas na mesma frase, e é a mesma saída já usada no
                        modo "ver filiais separadas" logo abaixo.
                     -->{{ contagemDeClientes }}<template v-if="statusFiltrado"> · {{ rotuloStatusFiltrado }}</template><!--
                        ⚠️ No modo "ver filiais separadas" a contagem do KPI (clientes) e a
                        da paginação (filiais) são DIFERENTES por definição, e ficam a cinco
                        centímetros uma da outra. A saída é dizer as duas unidades, nunca
                        esconder uma: número que precisa de legenda para não parecer errado
                        já perdeu a confiança, mas dois números rotulados não competem.
                        Agrupado — o padrão — este trecho some, porque aí os dois batem.
                     --><template v-if="! listaAgrupada">
                            · {{ clientes.total }} filiais
                        </template>
                        · {{ kpis.pctDentro }}% no segmento
                    </template>
                    <!-- Busca e ordenação NÃO colapsam: filtrar é ocasional, procurar um
                         cliente e reordenar a lista é o uso normal desta tela. -->
                    <template #filtrosFixos>
                        <div class="flex w-full flex-col gap-1 sm:min-w-[200px] sm:max-w-[280px] sm:flex-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Razão social, CNPJ ou código..."
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @input="onBuscaInput"
                            />
                        </div>

                        <!-- Só no celular, e só na aba que lista: no desktop quem ordena é o
                             clique no cabeçalho da tabela, que abaixo de 640px não existe. -->
                        <OrdenarMobile
                            v-if="aba === 'clientes'"
                            class="w-full"
                            :colunas="ORDENACOES_CARTEIRA"
                            :ordenar="filtros.ordenar"
                            @ordenar="ordenarPor"
                        />
                    </template>

                    <template #filtros>
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

                        <button type="button" class="min-h-11 rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 sm:min-h-0 sm:self-end" @click="limparFiltros">
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

                    <!-- Alternador de unidade da lista. Fica à direita das abas porque não
                         é uma aba: não troca de conteúdo, troca como o MESMO conteúdo é
                         contado. Só aparece na aba Clientes, que é a única que lista. -->
                    <button
                        v-if="aba === 'clientes'"
                        type="button"
                        class="ml-auto inline-flex items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
                        :title="listaAgrupada
                            ? 'Mostrar uma linha por filial, como era antes'
                            : 'Agrupar as filiais de cada cliente numa linha só'"
                        @click="alternarAgrupamento"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                            <path v-if="listaAgrupada" d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
                            <path v-else d="M4 6h16M8 12h12M8 18h12" stroke-linecap="round" />
                        </svg>
                        {{ listaAgrupada ? 'Ver filiais separadas' : 'Agrupar por cliente' }}
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
                            <!-- A reconciliação "são N empresas, listadas abaixo por filial"
                                 saiu em 2026-09-11: existia só porque o card contava empresas
                                 e a tabela listava filiais. Agrupada, a tabela lista as mesmas
                                 N empresas do card e não há nada a reconciliar. Ela volta a
                                 fazer falta se alguém estiver no modo "ver filiais separadas",
                                 e é por isso que o aviso continua, condicionado a ele. -->
                            <span v-if="! listaAgrupada && props.filtros.semFamiliaEmpresas" class="text-gray-500">
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

                    <DarkCard title="Carteira de Clientes" :subtitle="subtituloDaTabela">
                        <template #icon>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                                <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                                <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                                <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                            </svg>
                        </template>
                        <template #actions>
                            <!-- Sem `assincrono`: quem decide entre baixar na hora e ir
                                 para a fila é o volume, no servidor. Esta mesma tela leva
                                 ~95 s para um admin (92 mil clientes) e menos de 1 s para
                                 um vendedor com 283 — a prop fixa acertava só um dos dois. -->
                            <ExportarExcelButton
                                rota="carteira.exportar"
                                :filtros="filtros"
                                :tem-filtros-ativos="temFiltrosAtivos"
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
                            :agrupado="listaAgrupada"
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
