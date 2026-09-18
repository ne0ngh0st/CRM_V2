<script setup>
/**
 * Intranet — a lista de publicações.
 *
 * Tudo é uma "publicação" com categoria (Aviso, Regra de negócio, Workflow, Documento). A
 * "biblioteca de documentos" é só o filtro "Documento" desta lista, não uma tela à parte.
 *
 * Cartões e não tabela: o que importa aqui é o título e o começo do texto, e cartão
 * funciona igual no celular sem precisar do `.tbl-cartoes`.
 */
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import IconeNav from '@/Components/Icones/IconeNav.vue';
import SeloIntranet from '@/Components/Intranet/SeloIntranet.vue';
import { contarFiltrosAtivos } from '@/utils/filtros.js';
import { dataCurta } from '@/utils/formato.js';

const props = defineProps({
    publicacoes: { type: Object, required: true },
    filtros: { type: Object, required: true },
    categorias: { type: Array, required: true },
    podePublicar: { type: Boolean, default: false },
});

const filtros = reactive({
    categoria: props.filtros.categoria || '',
    busca: props.filtros.busca || '',
});

const filtrosAtivos = computed(() => contarFiltrosAtivos(filtros, ['categoria', 'busca']));

function aplicarFiltros() {
    router.get(route('intranet.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['publicacoes', 'filtros'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, { categoria: '', busca: '' });
    aplicarFiltros();
}

const itens = computed(() => props.publicacoes.data ?? []);
</script>

<template>
    <Head title="Intranet" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Intranet Autopel" :filtros-ativos="filtrosAtivos">
                    <template #icon>
                        <IconeNav nome="intranet" />
                    </template>
                    <template #subtitle>
                        Avisos, regras de negócio, workflows e documentos da gestão comercial.
                    </template>
                    <template v-if="podePublicar" #meta>
                        <Link
                            :href="route('intranet.create')"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded bg-intranet-dark px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-navy sm:min-h-0"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                                <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                            </svg>
                            Nova publicação
                        </Link>
                    </template>
                    <template #filtrosFixos>
                        <div class="flex w-full flex-col gap-1 sm:min-w-[200px] sm:max-w-[320px] sm:flex-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Título ou texto"
                                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-intranet-dark focus:ring-intranet-dark sm:min-h-0"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField
                            label="Categoria"
                            :model-value="filtros.categoria"
                            @update:model-value="(v) => { filtros.categoria = v; aplicarFiltros(); }"
                        >
                            <option value="">Todas</option>
                            <option v-for="c in categorias" :key="c.valor" :value="c.valor">{{ c.rotulo }}</option>
                        </FilterField>

                        <button
                            v-if="filtrosAtivos > 0"
                            type="button"
                            class="self-end text-xs font-medium text-intranet-dark hover:underline"
                            @click="limparFiltros"
                        >
                            Limpar
                        </button>
                    </template>
                </PageHero>

                <div
                    v-if="itens.length === 0"
                    class="rounded border border-gray-300 bg-white px-4 py-10 text-center shadow-sm"
                >
                    <p class="text-sm font-medium text-gray-600">
                        {{ filtrosAtivos > 0 ? 'Nenhuma publicação com esses filtros.' : 'Nada publicado ainda.' }}
                    </p>
                    <p v-if="podePublicar && filtrosAtivos === 0" class="mt-1 text-xs text-gray-400">
                        Use "Nova publicação" para o primeiro aviso da equipe.
                    </p>
                </div>

                <ul v-else class="flex flex-col gap-2">
                    <li v-for="p in itens" :key="p.id">
                        <!--
                            Filete à esquerda: roxo cheio para o que a pessoa ainda não abriu,
                            pastel para o que já leu. É o "não lido" de caixa de e-mail — o
                            olho acha o novo sem ler selo nenhum.
                        -->
                        <Link
                            :href="route('intranet.show', p.id)"
                            class="group flex overflow-hidden rounded border bg-white shadow-sm transition hover:border-intranet"
                            :class="p.lida ? 'border-gray-300' : 'border-intranet/60'"
                        >
                            <span class="w-1.5 shrink-0" :class="p.lida ? 'bg-intranet/40' : 'bg-intranet-dark'" />
                            <div class="min-w-0 flex-1 px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <SeloIntranet v-if="p.fixada" tom="fixada">Fixada</SeloIntranet>
                                    <SeloIntranet>{{ p.categoriaRotulo }}</SeloIntranet>
                                    <SeloIntranet v-if="!p.lida" tom="novo">Novo</SeloIntranet>
                                    <SeloIntranet v-if="p.importante" tom="importante">Importante</SeloIntranet>
                                    <SeloIntranet v-if="p.cienciaPendente" tom="pendente">Ciência pendente</SeloIntranet>
                                </div>
                                <h2
                                    class="mt-1.5 text-sm leading-snug text-gray-800 group-hover:text-intranet-dark"
                                    :class="p.lida ? 'font-semibold' : 'font-bold'"
                                >
                                    {{ p.titulo }}
                                </h2>
                                <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-gray-500">{{ p.resumo }}</p>
                                <p class="mt-1.5 text-[0.68rem] text-gray-400">
                                    {{ p.autor }} · {{ dataCurta(p.publicadaEm) }}
                                    <template v-if="p.editadaEm"> · editada {{ dataCurta(p.editadaEm) }}</template>
                                    <template v-if="p.totalAnexos"> · {{ p.totalAnexos }} {{ p.totalAnexos === 1 ? 'anexo' : 'anexos' }}</template>
                                </p>
                            </div>
                        </Link>
                    </li>
                </ul>

                <Pagination :meta="publicacoes" :only="['publicacoes', 'filtros']" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
