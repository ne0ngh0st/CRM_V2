<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Pagination from '@/Components/Pagination.vue';
import ProdutosTabela from '@/Components/TabelaPrecos/ProdutosTabela.vue';

const props = defineProps({
    role: String,
    produtos: Object,
    kpis: Object,
    filtros: Object,
    opcoes: Object,
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    categoria: props.filtros.categoria || '',
    preco: props.filtros.preco || 'todos',
    ordenar: props.filtros.ordenar || 'codigo_asc',
});

function aplicarFiltros() {
    router.get(route('tabela-precos.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['produtos', 'kpis', 'filtros'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, {
        busca: '',
        categoria: '',
        preco: 'todos',
        ordenar: 'codigo_asc',
    });
    aplicarFiltros();
}
</script>

<template>
    <Head title="Tabela de Preços" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Tabela de Preços">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round" />
                            <circle cx="18" cy="17" r="2.5" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Código TOTVS, descrição, unidade e preço tabela — consulta só leitura.
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.total" label="Produtos" />
                        <KpiTile :value="kpis.categorias" label="Categorias" />
                        <KpiTile :value="kpis.comPreco" label="Com preço" tone="ok" />
                        <KpiTile :value="kpis.semPreco" label="Sem preço" :tone="kpis.semPreco > 0 ? 'warn' : 'neutral'" />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[300px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Código ou descrição..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField
                            label="Categoria"
                            :model-value="filtros.categoria"
                            @update:model-value="(v) => { filtros.categoria = v; aplicarFiltros(); }"
                        >
                            <option value="">Todas</option>
                            <option
                                v-for="cat in opcoes.categorias"
                                :key="cat.nome"
                                :value="cat.nome"
                            >
                                {{ cat.nome }} ({{ cat.total }})
                            </option>
                        </FilterField>

                        <FilterField
                            label="Preço"
                            :model-value="filtros.preco"
                            @update:model-value="(v) => { filtros.preco = v; aplicarFiltros(); }"
                        >
                            <option value="todos">Todos</option>
                            <option value="com_preco">Com preço</option>
                            <option value="sem_preco">Sem preço</option>
                        </FilterField>

                        <FilterField
                            label="Ordenar por"
                            :model-value="filtros.ordenar"
                            @update:model-value="(v) => { filtros.ordenar = v; aplicarFiltros(); }"
                        >
                            <option value="codigo_asc">Código · A→Z</option>
                            <option value="codigo_desc">Código · Z→A</option>
                            <option value="descricao_asc">Descrição · A→Z</option>
                            <option value="descricao_desc">Descrição · Z→A</option>
                            <option value="categoria_asc">Categoria</option>
                            <option value="preco_asc">Preço · menor</option>
                            <option value="preco_desc">Preço · maior</option>
                        </FilterField>

                        <button
                            type="button"
                            class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
                            @click="limparFiltros"
                        >
                            Limpar filtros
                        </button>
                    </template>
                </PageHero>

                <DarkCard
                    title="Produtos"
                    :subtitle="`${kpis.total} produto${kpis.total !== 1 ? 's' : ''} na tabela`"
                >
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round" />
                            <circle cx="18" cy="17" r="2.5" />
                        </svg>
                    </template>

                    <ProdutosTabela v-if="produtos.data.length" :produtos="produtos.data" />
                    <p v-else class="text-sm text-gray-400">Nenhum produto encontrado com os filtros atuais.</p>

                    <div class="mt-4">
                        <Pagination :meta="produtos" :only="['produtos']" />
                    </div>
                </DarkCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
