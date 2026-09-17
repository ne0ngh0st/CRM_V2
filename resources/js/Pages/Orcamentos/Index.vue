<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import OrcamentosTabela from '@/Components/Orcamentos/OrcamentosTabela.vue';
import RejeitarOrcamentoModal from '@/Components/Orcamentos/RejeitarOrcamentoModal.vue';
import ExcluirOrcamentoModal from '@/Components/Orcamentos/ExcluirOrcamentoModal.vue';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import { useConfirmacao } from '@/composables/useConfirmacao.js';
import ExportarExcelButton from '@/Components/ExportarExcelButton.vue';
import { ROTULOS_STATUS_ORCAMENTO, ROTULOS_NIVEL_APROVACAO } from '@/constants/orcamentos.js';
import { contarFiltrosAtivos } from '@/utils/filtros';

const props = defineProps({
    role: String,
    podeExcluir: Boolean,
    portalHabilitado: { type: Boolean, default: false },
    orcamentos: Object,
    kpis: Object,
    filaAprovacao: { type: Object, default: null },
    filtros: Object,
    visao: Object,
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    status: props.filtros.status || '',
    nivel: props.filtros.nivel || '',
    data_inicio: props.filtros.data_inicio || '',
    data_fim: props.filtros.data_fim || '',
    visao_supervisor: props.visao.visaoSupervisor || '',
    visao_vendedor: props.visao.visaoVendedor || '',
});

function aplicarFiltros() {
    router.get(route('orcamentos.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['orcamentos', 'kpis', 'filtros', 'visao'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '', status: '', nivel: '', data_inicio: '', data_fim: '',
        visao_supervisor: '', visao_vendedor: '',
    });
    aplicarFiltros();
}

/*
 * Uma lista de campos, dois consumidores: a contagem no botão "Filtros" do celular e o
 * aviso do Excel.
 *
 * ⚠️ O badge conta só o que o botão ESCONDE — a busca fica visível ao lado dele. O aviso
 * do Excel responde outra pergunta ("o arquivo sai recortado?") e aí a busca conta.
 */
const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, [
    'status', 'nivel', 'data_inicio', 'data_fim',
    'visao_supervisor', 'visao_vendedor',
]));

const temFiltrosAtivos = computed(() => filtrosAtivos.value > 0 || filtros.busca !== '');

/*
 * "12 de 180 orçamentos · Pendente" quando a lista é um recorte, "180 orçamentos"
 * quando não é. Decide COMPARANDO os dois números, nunca perguntando quais filtros
 * estão ativos — mesma regra da Carteira (`contagemDeClientes`).
 */
const contagemDeOrcamentos = computed(() => {
    const total = props.kpis.total;
    const rotulo = `${total} orçamento${total !== 1 ? 's' : ''}`;

    return props.orcamentos.total !== total
        ? `${props.orcamentos.total} de ${rotulo}`
        : rotulo;
});

const recorteDaFila = computed(() => {
    const fila = props.filaAprovacao;
    if (!fila) return false;

    return filtros.status === fila.status && filtros.nivel === fila.nivel;
});

const subtituloHero = computed(() => (
    recorteDaFila.value
        ? 'Fila de aprovação — só o que precisa da sua decisão.'
        : 'Aprovação interna por nível de desconto — supervisor ou diretor conforme a regra de negócio.'
));

const vendoTodos = computed(() => (
    props.filtros.ver === 'todos' && !filtros.status && !filtros.nivel
));

function hrefOrcamentos(recorte) {
    return route('orcamentos.index', {
        busca: filtros.busca || undefined,
        data_inicio: filtros.data_inicio || undefined,
        data_fim: filtros.data_fim || undefined,
        visao_supervisor: filtros.visao_supervisor || undefined,
        visao_vendedor: filtros.visao_vendedor || undefined,
        ...recorte,
    });
}

function facetaAtiva(recorte) {
    if (recorte.ver === 'todos') {
        return vendoTodos.value || (!props.filaAprovacao && !filtros.status && !filtros.nivel);
    }

    return Object.entries(recorte).every(([chave, valor]) => (filtros[chave] || '') === valor)
        && (recorte.nivel ? true : !filtros.nivel);
}

function hrefFaceta(recorte) {
    if (facetaAtiva(recorte)) {
        if (recorte.ver === 'todos') {
            return hrefOrcamentos(props.filaAprovacao ? {} : { ver: 'todos' });
        }

        return hrefOrcamentos({ ver: 'todos' });
    }

    return hrefOrcamentos(recorte);
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

const modalRejeitar = ref(false);
const modalExcluir = ref(false);
const orcamentoAtivo = ref(null);

function abrirRejeitar(orcamento) {
    orcamentoAtivo.value = orcamento;
    modalRejeitar.value = true;
}

function abrirExcluir(orcamento) {
    orcamentoAtivo.value = orcamento;
    modalExcluir.value = true;
}

function aprovar(orcamento) {
    router.patch(route('orcamentos.aprovar', orcamento.id), {}, { preserveScroll: true, preserveState: true });
}

// Aviso do envio ao Portal. Vem do flash porque a validação do de-para acontece
// DENTRO da requisição: "falta o representante" ou "o CNPJ não bate" precisa aparecer
// na hora, e não pelo sino minutos depois.
const portalAviso = computed(() => usePage().props.flash?.portalAviso ?? null);

const { confirmacao, confirmar, aoConfirmar, aoCancelar } = useConfirmacao();

async function enviarAoPortal(orcamento) {
    // ⚠️ Confirmação explícita: isto cria um pedido no Portal, que é ação para fora do
    // CRM e não tem desfazer por aqui.
    const ok = await confirmar({
        titulo: 'Transformar em pedido',
        subtitulo: orcamento.clienteNome,
        mensagem: `O orçamento #${orcamento.id} (${formatBRL(orcamento.valorTotal)}) vira um pedido no Portal Autopel.`,
        detalhe: 'O pedido é criado fora do CRM e não há como desfazer por aqui.',
        rotuloConfirmar: 'Transformar em pedido',
        tom: 'atencao',
    });
    if (!ok) return;

    router.post(route('orcamentos.portal', orcamento.id), {}, {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <Head title="Orçamentos" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Orçamentos" :filtros-ativos="filtrosAtivos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="6" y="3" width="12" height="18" rx="1" />
                            <line x1="9" y1="8" x2="15" y2="8" stroke-linecap="round" />
                            <line x1="9" y1="12" x2="15" y2="12" stroke-linecap="round" />
                            <line x1="9" y1="16" x2="13" y2="16" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        {{ subtituloHero }}
                    </template>
                    <template #meta>
                        <KpiTile
                            :value="kpis.total"
                            label="Total"
                            :href="hrefFaceta({ ver: 'todos' })"
                            :ativo="facetaAtiva({ ver: 'todos' })"
                        />
                        <KpiTile
                            :value="kpis.aguardandoSupervisor"
                            label="Aguard. supervisor"
                            tone="warn"
                            :href="hrefFaceta({ status: 'pendente', nivel: 'supervisor' })"
                            :ativo="facetaAtiva({ status: 'pendente', nivel: 'supervisor' })"
                        />
                        <KpiTile
                            :value="kpis.aguardandoDiretor"
                            label="Aguard. diretor"
                            tone="warn"
                            :href="hrefFaceta({ status: 'pendente', nivel: 'diretor' })"
                            :ativo="facetaAtiva({ status: 'pendente', nivel: 'diretor' })"
                        />
                        <KpiTile
                            :value="kpis.aprovados"
                            label="Aprovados"
                            tone="ok"
                            :href="hrefFaceta({ status: 'aprovado' })"
                            :ativo="facetaAtiva({ status: 'aprovado' })"
                        />
                        <KpiTile
                            :value="kpis.rejeitados"
                            label="Rejeitados"
                            tone="danger"
                            :href="hrefFaceta({ status: 'rejeitado' })"
                            :ativo="facetaAtiva({ status: 'rejeitado' })"
                        />
                        <KpiTile
                            :value="formatBRL(kpis.valorAprovado)"
                            label="Valor aprovado"
                            compact
                            :href="hrefFaceta({ status: 'aprovado' })"
                            :ativo="facetaAtiva({ status: 'aprovado' })"
                        />
                    </template>
                    <template #filtrosFixos>
                        <div class="flex w-full flex-col gap-1 sm:min-w-[200px] sm:max-w-[280px] sm:flex-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Cliente ou CNPJ..."
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @input="onBuscaInput"
                            />
                        </div>
                    </template>

                    <template #filtros>
                        <FilterField label="Status" :model-value="filtros.status" @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="(rotulo, valor) in ROTULOS_STATUS_ORCAMENTO" :key="valor" :value="valor">{{ rotulo }}</option>
                        </FilterField>

                        <FilterField label="Nível" :model-value="filtros.nivel" @update:model-value="(v) => { filtros.nivel = v; aplicarFiltros(); }">
                            <option value="">Todos</option>
                            <option v-for="(rotulo, valor) in ROTULOS_NIVEL_APROVACAO" :key="valor" :value="valor">{{ rotulo }}</option>
                        </FilterField>

                        <div class="flex flex-col gap-1 sm:min-w-[130px]">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Criado de</label>
                            <input
                                v-model="filtros.data_inicio"
                                type="date"
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @change="aplicarFiltros"
                            />
                        </div>

                        <div class="flex flex-col gap-1 sm:min-w-[130px]">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Criado até</label>
                            <input
                                v-model="filtros.data_fim"
                                type="date"
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
                                @change="aplicarFiltros"
                            />
                        </div>

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

                <DarkCard title="Orçamentos" :subtitle="contagemDeOrcamentos">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <line x1="4" y1="6" x2="20" y2="6" stroke-linecap="round" />
                            <line x1="4" y1="12" x2="20" y2="12" stroke-linecap="round" />
                            <line x1="4" y1="18" x2="20" y2="18" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <ExportarExcelButton
                            rota="orcamentos.exportar"
                            :filtros="filtros"
                            :tem-filtros-ativos="temFiltrosAtivos"
                        />
                        <Link
                            v-if="role === 'admin'"
                            :href="route('etiquetas.materiaPrima.index')"
                            class="rounded border border-gray-600 px-3 py-1 text-xs font-medium text-gray-200 transition hover:bg-white/10"
                        >
                            Matéria-prima de etiqueta
                        </Link>
                        <Link :href="route('orcamentos.novo')" class="rounded bg-teal px-3 py-1 text-xs font-medium text-white hover:bg-teal/90">
                            + Novo Orçamento
                        </Link>
                    </template>

                    <!--
                        Aviso do envio ao Portal. Verde = foi para a fila (o número do
                        pedido chega pelo sino); vermelho = recusado ANTES de sair, e a
                        mensagem diz o que fazer.
                    -->
                    <div
                        v-if="portalAviso"
                        class="mb-3 rounded border px-3 py-2 text-sm"
                        :class="portalAviso.tipo === 'ok'
                            ? 'border-green-300 bg-green-50 text-green-800'
                            : 'border-red-300 bg-red-50 text-red-800'"
                    >
                        {{ portalAviso.mensagem }}
                    </div>

                    <OrcamentosTabela
                        v-if="orcamentos.data.length"
                        :orcamentos="orcamentos.data"
                        :pode-excluir="podeExcluir"
                        @editar="(o) => router.visit(route('orcamentos.editar', o.id))"
                        @copiar="(o) => router.visit(route('orcamentos.novo', { copiar_de: o.id }))"
                        @aprovar="aprovar"
                        @rejeitar="abrirRejeitar"
                        @excluir="abrirExcluir"
                        @enviar-ao-portal="enviarAoPortal"
                    />
                    <p v-else class="text-sm text-gray-400">
                        {{ recorteDaFila
                            ? 'Nenhum orçamento aguardando sua aprovação.'
                            : 'Nenhum orçamento encontrado com os filtros atuais.' }}
                    </p>

                    <div class="mt-4">
                        <Pagination :meta="orcamentos" :only="['orcamentos']" />
                    </div>
                </DarkCard>
            </div>
        </div>

        <RejeitarOrcamentoModal :show="modalRejeitar" :orcamento="orcamentoAtivo" @close="modalRejeitar = false" />
        <ExcluirOrcamentoModal :show="modalExcluir" :orcamento="orcamentoAtivo" @close="modalExcluir = false" />

        <ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
    </AuthenticatedLayout>
</template>
