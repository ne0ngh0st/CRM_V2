<script setup>
/**
 * A Carteira: Clientes · Leads · Funil · Calendário, com UMA busca que vale para todas.
 *
 * Pedido da equipe comercial (2026-09-29): procurar um nome e achá-lo sem trocar de
 * página, seja cliente ou lead. Até então eram duas páginas (`/carteira` e `/leads`);
 * `/leads` hoje só redireciona para cá.
 *
 * O que é da página (este arquivo): busca, visão, filtros, a troca de aba e os contadores.
 * O que é de cada aba mora no componente dela (`AbaClientes`, `AbaLeads`, `FunilQuadro`,
 * `CalendarioAgendamentos`).
 *
 * 🥇 Cada aba só pede ao servidor o que ela mostra (`PROPS_POR_ABA`). Juntar as páginas
 * não pode somar o custo delas — Regra de ouro nº 9.
 */
import { computed, reactive, ref, watch } from 'vue';
import { PERFIS_ESCOPO_PROPRIO } from '@/constants/perfis.js';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import EscopoVazioAviso from '@/Components/EscopoVazioAviso.vue';
import CarteiraSegmentoCard from '@/Components/Dashboard/CarteiraSegmentoCard.vue';
import CalendarioAgendamentos from '@/Components/Carteira/CalendarioAgendamentos.vue';
import AbaClientes from '@/Components/Carteira/AbaClientes.vue';
import AbaLeads from '@/Components/Leads/AbaLeads.vue';
import FunilQuadro from '@/Components/Leads/FunilQuadro.vue';
import WordpressCapturaBar from '@/Components/Leads/WordpressCapturaBar.vue';
import OrdenarMobile from '@/Components/Tabela/OrdenarMobile.vue';
import { ROTULOS_STATUS_CARTEIRA, ORDENACOES_CARTEIRA } from '@/constants/carteira';
import { ETAPAS_LEAD, ROTULOS_ETAPA_LEAD } from '@/constants/leads.js';
import { contarFiltrosAtivos } from '@/utils/filtros';

const props = defineProps({
    role: String,
    aba: { type: String, default: 'clientes' },
    // Cada grupo abaixo só vem preenchido quando a aba dele é a ativa.
    clientes: { type: Object, default: null },
    kpis: { type: Object, default: null },
    opcoes: { type: Object, default: null },
    leads: { type: Object, default: null },
    leadsKpis: { type: Object, default: null },
    leadsOpcoes: { type: Object, default: null },
    wordpressCaptura: { type: Object, default: null },
    funil: { type: Object, default: null },
    agendamentos: { type: Array, default: () => [] },
    filtros: Object,
    visao: Object,
});

const page = usePage();

/**
 * Quem pode OPERAR (ligar, agendar, orçar) — não é a mesma pergunta de quem pode VER.
 *
 * ⚠️ Nos clientes inclui o supervisor em modo "Minha carteira": na Autopel supervisor
 * também vende, e nesse modo a lista é a carteira PESSOAL dele. Em modo Equipe os
 * clientes são de outras pessoas, e registrar contato no lugar do vendedor sujaria a
 * métrica de atividade dele.
 */
const podeOperar = computed(
    () => PERFIS_ESCOPO_PROPRIO.includes(props.role)
        || (props.role === 'supervisor' && page.props.modoVisao?.modo === 'pessoal'),
);
const podeAgirNoLead = computed(() => PERFIS_ESCOPO_PROPRIO.includes(props.role));

// ---------------------------------------------------------------- abas e filtros

const ABAS = [
    { chave: 'clientes', rotulo: 'Clientes' },
    { chave: 'leads', rotulo: 'Leads' },
    { chave: 'funil', rotulo: 'Funil' },
    { chave: 'calendario', rotulo: 'Calendário' },
];

/** O que cada aba pede ao servidor, além do que é comum. */
const PROPS_COMUNS = ['filtros', 'visao', 'aba'];
const PROPS_POR_ABA = {
    clientes: ['clientes', 'kpis', 'opcoes'],
    leads: ['leads', 'leadsKpis', 'leadsOpcoes', 'wordpressCaptura'],
    funil: ['funil', 'leadsKpis'],
    calendario: ['agendamentos'],
};

/**
 * Os filtros PRÓPRIOS de cada grupo de abas. Leads e Funil compartilham os deles (o funil
 * é o mesmo conjunto de leads desenhado por etapa).
 *
 * ⚠️ `status`, `segmento`, `estado` e `ordenar` existem nos dois grupos com significados
 * DIFERENTES (situação do cliente × etapa do lead; código do TOTVS × texto livre). Por
 * isso a troca de grupo zera esses filtros e leva só a busca e a visão: levá-los para o
 * outro lado daria tela vazia sem erro nenhum.
 */
const FILTROS_DO_GRUPO = {
    clientes: ['estado', 'segmento', 'status', 'aderencia', 'sem_familia', 'conta_alvo', 'ordenar', 'agrupar'],
    leads: ['estado', 'segmento', 'status', 'origem', 'ordenar'],
    calendario: [],
};
const PADRAO_DOS_FILTROS = {
    estado: '', segmento: '', status: '', aderencia: '', sem_familia: '', conta_alvo: '',
    origem: '', ordenar: 'nome_asc', agrupar: '',
};

function grupoDa(aba) {
    return aba === 'funil' ? 'leads' : aba;
}

const filtros = reactive({
    busca: props.filtros.busca || '',
    estado: props.filtros.estado || '',
    segmento: props.filtros.segmento || '',
    status: props.filtros.status || '',
    aderencia: props.filtros.aderencia || '',
    origem: props.filtros.origem || '',
    // Vem do card de Potencial da Carteira do Painel; anunciado por faixa, sem campo.
    sem_familia: props.filtros.semFamilia || '',
    // Vem da Visão Diretor → Maiores por Segmento. Também anunciado por faixa.
    conta_alvo: props.filtros.contaAlvo?.id ? String(props.filtros.contaAlvo.id) : '',
    ordenar: props.filtros.ordenar || 'nome_asc',
    // Vazio = padrão (agrupado por cliente). Só o modo por filial precisa ir na URL.
    agrupar: props.filtros.agrupado ? '' : '0',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

/** Busca e visão (valem para todas as abas) + os filtros do grupo da aba. */
function paramsDaAba(aba) {
    const params = {
        aba,
        busca: filtros.busca,
        visao_supervisor: filtros.visao_supervisor,
        visao_vendedor: filtros.visao_vendedor,
    };
    for (const chave of FILTROS_DO_GRUPO[grupoDa(aba)]) {
        params[chave] = filtros[chave];
    }
    return params;
}

function visitar(aba) {
    router.get(route('carteira.index'), paramsDaAba(aba), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: [...PROPS_COMUNS, ...PROPS_POR_ABA[aba]],
    });
}

function aplicarFiltros() {
    visitar(props.aba);
}

function trocarAba(aba) {
    if (aba === props.aba) return;
    if (grupoDa(aba) !== grupoDa(props.aba)) {
        Object.assign(filtros, PADRAO_DOS_FILTROS);
    }
    visitar(aba);
}

// Volta pra página 1 de propósito: manter o offset ao reordenar deixa o usuário no meio
// de uma lista que não é mais a mesma.
function ordenarPor(valor) {
    filtros.ordenar = valor;
    aplicarFiltros();
}

let timeoutBusca;
function onBuscaInput() {
    buscaDigitada = true;
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, PADRAO_DOS_FILTROS, { busca: '', visao_supervisor: '', visao_vendedor: '' });
    aplicarFiltros();
}

function limparFiltro(chave) {
    filtros[chave] = '';
    aplicarFiltros();
}

/*
 * Uma lista de campos, dois consumidores: a contagem no botão "Filtros" do celular e o
 * aviso do Excel.
 *
 * ⚠️ O BADGE CONTA SÓ O QUE O BOTÃO ESCONDE. A busca fica visível ao lado dele, então
 * somá-la faria o botão dizer "1" com o modal de filtros aparentemente vazio. O aviso do
 * Excel é outra pergunta — "este arquivo sai recortado?" — e aí a busca conta.
 */
const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, [
    ...FILTROS_DO_GRUPO[grupoDa(props.aba)].filter((c) => ! ['ordenar', 'agrupar'].includes(c)),
    'visao_supervisor', 'visao_vendedor',
]));
const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '');

// ---------------------------------------------------------------- contadores das abas

/*
 * "Clientes (2.901) · Leads (15)": com uma busca de 3+ letras, cada aba diz quantos
 * resultados tem — é o que impede "busquei, não achei, desisti" quando o nome está na
 * outra aba.
 *
 * A aba ativa usa o próprio total; a outra pergunta a `BuscaCruzada` (`/busca-cruzada`),
 * que conta exatamente o que aquela aba mostraria com essa busca (travado por teste).
 *
 * ⚠️ Olha a busca APLICADA (`props.filtros.busca`, resposta do servidor), nunca o que está
 * sendo digitado: a contagem sai depois que a lista já voltou, fora da primeira pintura,
 * e uma vez por termo — não a cada tecla.
 */
const MINIMO_CARACTERES = 3; // mesmo valor de BuscaCruzada::MINIMO_CARACTERES

const termoAplicado = computed(() => (props.filtros.busca || '').trim());
const contagemExterna = reactive({ clientes: null, leads: null });
let controleContagem = null;
let buscaDigitada = false;

const listaAgrupada = computed(() => !! props.filtros.agrupado);

/** O total que a própria aba já sabe, quando ela é a ativa. */
function totalProprio(alvo) {
    if (alvo === 'clientes' && props.aba === 'clientes' && props.clientes) {
        // Por filial, o total da paginação conta FILIAIS; o contador conta clientes.
        return listaAgrupada.value ? props.clientes.total : null;
    }
    if (alvo === 'leads' && props.aba === 'leads' && props.leads) return props.leads.total;
    return null;
}

function contador(alvo) {
    // Só Clientes e Leads têm contador. Funil e Calendário não entram na pergunta
    // "em qual aba o nome está" — devolver undefined aqui faria o botão chamar
    // toLocaleString em nada e a página quebrar no meio da busca.
    if (alvo !== 'clientes' && alvo !== 'leads') return null;
    if (termoAplicado.value.length < MINIMO_CARACTERES) return null;
    return totalProprio(alvo) ?? contagemExterna[alvo] ?? null;
}

async function atualizarContadores() {
    controleContagem?.abort();
    contagemExterna.clientes = null;
    contagemExterna.leads = null;
    const pularSeVazio = buscaDigitada;
    buscaDigitada = false;

    if (termoAplicado.value.length < MINIMO_CARACTERES) return;

    controleContagem = new AbortController();
    const pedidos = ['clientes', 'leads']
        .filter((alvo) => totalProprio(alvo) === null)
        .map(async (alvo) => {
            const params = { alvo, busca: termoAplicado.value };
            if (filtros.visao_supervisor) params.visao_supervisor = filtros.visao_supervisor;
            if (filtros.visao_vendedor) params.visao_vendedor = filtros.visao_vendedor;
            const res = await fetch(route('buscaCruzada', params), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controleContagem.signal,
            });
            if (res.ok) contagemExterna[alvo] = (await res.json()).total ?? 0;
        });

    try {
        await Promise.all(pedidos);
    } catch {
        // Abortado por um termo mais novo, ou rede fora: contador é dica, não pode
        // derrubar nada. Sem resposta, a aba fica sem número.
        return;
    }

    if (pularSeVazio) pularParaOndeTemResultado();
}

/*
 * Busca que só existe na outra aba: pula para ela. É o que resolve de verdade o
 * "busquei na Carteira e não achei" — o vendedor não precisa saber que era lead.
 *
 * ⚠️ Só quando a busca veio do DIGITAR (não num F5 ou link), só entre Clientes e Leads,
 * e só sem outro filtro da aba aplicado: com "status = inativo" ligado, a lista vazia é
 * uma resposta, não um engano de aba.
 */
function pularParaOndeTemResultado() {
    const outra = { clientes: 'leads', leads: 'clientes' }[props.aba];
    // A visão (supervisor/vendedor) não conta: ela vale para as duas abas.
    const filtrosDaAba = contarFiltrosAtivos(filtros, FILTROS_DO_GRUPO[grupoDa(props.aba)]
        .filter((c) => ! ['ordenar', 'agrupar'].includes(c)));
    if (! outra || filtrosDaAba > 0) return;

    const aqui = props.aba === 'clientes' ? props.clientes?.total : props.leads?.total;
    if (aqui === 0 && (contador(outra) ?? 0) > 0) trocarAba(outra);
}

watch(
    () => [termoAplicado.value, props.aba, props.visao.visaoSupervisor, props.visao.visaoVendedor],
    atualizarContadores,
    { immediate: true },
);

// ---------------------------------------------------------------- cabeçalho por aba

/*
 * "31.651 de 39.692 clientes" quando a lista é um recorte, "39.692 clientes" quando não
 * é. ⚠️ Decide COMPARANDO os dois números, nunca perguntando quais filtros estão ativos:
 * continua verdadeiro quando um filtro novo entrar na tela sem ninguém lembrar daqui.
 */
const contagemDeClientes = computed(() => {
    if (! props.kpis || ! props.clientes) return '';
    const total = props.kpis.total;
    const rotulo = `${total} cliente${total !== 1 ? 's' : ''}`;

    return listaAgrupada.value && props.clientes.total !== total
        ? `${props.clientes.total} de ${rotulo}`
        : rotulo;
});

const subtituloDaTabela = computed(() => (
    listaAgrupada.value && props.clientes && props.kpis && props.clientes.total !== props.kpis.total
        ? contagemDeClientes.value
        : `${contagemDeClientes.value} no escopo atual`
));

// Só no modo agrupado: por filial, "543 de 1.199 clientes" misturaria duas unidades.
const statusFiltrado = computed(() => (props.aba === 'clientes' && listaAgrupada.value ? props.filtros.status || '' : ''));

function alternarAgrupamento() {
    filtros.agrupar = listaAgrupada.value ? '0' : '';
    aplicarFiltros();
}

// O funil copia as colunas ao montar; remontá-lo quando o recorte muda é o que faz a
// busca valer para ele.
const chaveDoFunil = computed(() => JSON.stringify(props.filtros) + JSON.stringify(props.visao.visaoVendedor ?? ''));
const filtrosDoFunil = computed(() => {
    const { aba, ...resto } = paramsDaAba('funil');
    return resto;
});

const ehGrupoLeads = computed(() => grupoDa(props.aba) === 'leads');
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
                    <template #subtitle>
                        <template v-if="aba === 'clientes' && kpis"><!--
                            ⚠️ Com um status filtrado, `kpis.total` é a CARTEIRA INTEIRA (o
                            card não aplica a si a faceta que desenha) e a lista é o recorte:
                            "543 de 1.199" responde as duas perguntas na mesma frase.
                         -->{{ contagemDeClientes }}<template v-if="statusFiltrado"> · {{ ROTULOS_STATUS_CARTEIRA[statusFiltrado] ?? statusFiltrado }}</template><template v-if="! listaAgrupada"> · {{ clientes.total }} filiais</template> · {{ kpis.pctDentro }}% no segmento
                        </template>
                        <template v-else-if="ehGrupoLeads">
                            Prospects — base do sistema, leads manuais e leads do site (WordPress).
                        </template>
                        <template v-else>
                            Agenda de ligações de clientes e leads.
                        </template>
                    </template>
                    <template v-if="ehGrupoLeads && leadsKpis" #meta>
                        <KpiTile :value="leadsKpis.total" label="Total" />
                        <KpiTile :value="leadsKpis.sistema" label="Sistema" tone="info" :href="route('carteira.index', { ...paramsDaAba('leads'), origem: 'sistema' })" />
                        <KpiTile :value="leadsKpis.manual" label="Manuais" tone="warn" :href="route('carteira.index', { ...paramsDaAba('leads'), origem: 'manual' })" />
                        <KpiTile :value="leadsKpis.wordpress ?? 0" label="WordPress" tone="ok" :href="route('carteira.index', { ...paramsDaAba('leads'), origem: 'wordpress' })" />
                        <KpiTile :value="leadsKpis.ativos" label="Em jogo" tone="ok" />
                    </template>

                    <!-- Busca e ordenação NÃO colapsam: procurar é o uso normal desta tela. -->
                    <template #filtrosFixos>
                        <div class="flex w-full flex-col gap-1 sm:min-w-[240px] sm:max-w-[320px] sm:flex-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar clientes e leads</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Nome, CNPJ, código, e-mail ou telefone..."
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @input="onBuscaInput"
                            />
                        </div>

                        <!-- Clientes: só no celular (no desktop quem ordena é o cabeçalho da
                             tabela, que abaixo de 640px não existe). -->
                        <OrdenarMobile
                            v-if="aba === 'clientes'"
                            class="w-full"
                            :colunas="ORDENACOES_CARTEIRA"
                            :ordenar="filtros.ordenar"
                            @ordenar="ordenarPor"
                        />
                        <FilterField v-else-if="aba === 'leads'" label="Ordenar" :model-value="filtros.ordenar" @update:model-value="ordenarPor">
                            <option value="nome_asc">Nome</option>
                            <option value="valor_desc">Valor estimado</option>
                            <option value="recentes">Mais recentes</option>
                        </FilterField>
                    </template>

                    <template #filtros>
                        <template v-if="aba === 'clientes' && opcoes">
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
                                <option v-for="(rotulo, valor) in ROTULOS_STATUS_CARTEIRA" :key="valor" :value="valor">{{ rotulo }}</option>
                            </FilterField>
                            <FilterField label="Aderência" :model-value="filtros.aderencia" @update:model-value="(v) => { filtros.aderencia = v; aplicarFiltros(); }">
                                <option value="">Todas</option>
                                <option value="dentro">No segmento</option>
                                <option value="fora">Fora do segmento</option>
                                <option value="sem_segmento">Sem segmento definido</option>
                            </FilterField>
                        </template>

                        <template v-else-if="ehGrupoLeads">
                            <FilterField label="UF" :model-value="filtros.estado" @update:model-value="(v) => { filtros.estado = v; aplicarFiltros(); }">
                                <option value="">Todos</option>
                                <option v-for="e in leadsOpcoes?.estados ?? []" :key="e" :value="e">{{ e }}</option>
                            </FilterField>
                            <FilterField label="Segmento" :model-value="filtros.segmento" @update:model-value="(v) => { filtros.segmento = v; aplicarFiltros(); }">
                                <option value="">Todos</option>
                                <option v-for="s in leadsOpcoes?.segmentos ?? []" :key="s" :value="s">{{ s }}</option>
                            </FilterField>
                            <FilterField label="Origem" :model-value="filtros.origem" @update:model-value="(v) => { filtros.origem = v; aplicarFiltros(); }">
                                <option value="">Todas</option>
                                <option value="sistema">Sistema</option>
                                <option value="manual">Manual</option>
                                <option value="wordpress">WordPress</option>
                            </FilterField>
                            <!-- ⚠️ A chave da query é `status` embora filtre `etapa`: renomear
                                 quebraria link salvo. -->
                            <FilterField v-if="aba === 'leads'" label="Etapa" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                                <option value="">Todas</option>
                                <option v-for="etapa in ETAPAS_LEAD" :key="etapa" :value="etapa">{{ ROTULOS_ETAPA_LEAD[etapa] }}</option>
                            </FilterField>
                        </template>

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

                <template v-if="aba === 'clientes' && kpis">
                    <EscopoVazioAviso :total="kpis.total" recurso="cliente" />
                    <CarteiraSegmentoCard :carteira-segmento="kpis" :base-filtros="filtros" />
                </template>
                <WordpressCapturaBar v-if="aba === 'leads' && wordpressCaptura" :captura="wordpressCaptura" />

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="a in ABAS"
                        :key="a.chave"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded border px-3 py-1.5 text-xs font-semibold uppercase tracking-wide transition"
                        :class="aba === a.chave ? 'border-navy bg-navy text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-100'"
                        @click="trocarAba(a.chave)"
                    >
                        {{ a.rotulo }}
                        <!-- Contador só com busca: é ele que avisa em qual aba o nome está. -->
                        <span
                            v-if="contador(a.chave) !== null"
                            class="rounded-full px-1.5 py-px text-[0.65rem] font-bold tabular-nums"
                            :class="aba === a.chave ? 'bg-white/20 text-white' : (contador(a.chave) > 0 ? 'bg-cyan/15 text-cyan-dark' : 'bg-gray-100 text-gray-400')"
                        >{{ contador(a.chave).toLocaleString('pt-BR') }}</span>
                    </button>

                    <!-- Alternador de unidade da lista. Não é aba: troca como o MESMO
                         conteúdo é contado. Só na aba Clientes. -->
                    <button
                        v-if="aba === 'clientes'"
                        type="button"
                        class="ml-auto inline-flex items-center gap-1.5 rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
                        :title="listaAgrupada ? 'Mostrar uma linha por filial, como era antes' : 'Agrupar as filiais de cada cliente numa linha só'"
                        @click="alternarAgrupamento"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                            <path v-if="listaAgrupada" d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
                            <path v-else d="M4 6h16M8 12h12M8 18h12" stroke-linecap="round" />
                        </svg>
                        {{ listaAgrupada ? 'Ver filiais separadas' : 'Agrupar por cliente' }}
                    </button>
                </div>

                <AbaClientes
                    v-if="aba === 'clientes' && clientes"
                    :clientes="clientes"
                    :filtros="filtros"
                    :tem-filtros-ativos="temFiltrosAtivos"
                    :pode-operar="podeOperar"
                    :lista-agrupada="listaAgrupada"
                    :subtitulo="subtituloDaTabela"
                    :sem-familia-rotulo="props.filtros.semFamiliaRotulo"
                    :sem-familia-empresas="props.filtros.semFamiliaEmpresas"
                    :conta-alvo="props.filtros.contaAlvo"
                    @ordenar="ordenarPor"
                    @limpar-sem-familia="limparFiltro('sem_familia')"
                    @limpar-conta-alvo="limparFiltro('conta_alvo')"
                />

                <AbaLeads
                    v-else-if="aba === 'leads' && leads"
                    :leads="leads"
                    :filtros="filtros"
                    :tem-filtros-ativos="temFiltrosAtivos"
                    :pode-agir="podeAgirNoLead"
                />

                <DarkCard v-else-if="aba === 'funil'" title="Funil" subtitle="Onde cada negociação está — mais parado primeiro">
                    <template #icon>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="h-4 w-4">
                            <path d="M3 4h14l-5 6v6l-4 2v-8L3 4z" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <FunilQuadro v-if="funil" :key="chaveDoFunil" :funil="funil" :filtros="filtrosDoFunil" />
                    <p v-else class="py-6 text-center text-sm text-gray-400">Carregando o funil…</p>
                </DarkCard>

                <CalendarioAgendamentos v-else-if="aba === 'calendario'" :agendamentos="agendamentos" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
