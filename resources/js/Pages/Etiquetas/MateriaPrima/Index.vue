<script setup>
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import MateriasPrimaTabela from '@/Components/Etiquetas/MateriasPrimaTabela.vue';
import MateriaPrimaFormModal from '@/Components/Etiquetas/MateriaPrimaFormModal.vue';

const props = defineProps({
    materiasPrimas: Object,
    filtros: Object,
});

const filtros = reactive({
    busca: props.filtros.busca || '',
    status: props.filtros.status || 'todos',
});

function aplicarFiltros() {
    router.get(route('etiquetas.materiaPrima.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['materiasPrimas', 'filtros'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

const modalForm = ref(false);
const materiaPrimaAtiva = ref(null);

function novaMateriaPrima() {
    materiaPrimaAtiva.value = null;
    modalForm.value = true;
}

function editarMateriaPrima(mp) {
    materiaPrimaAtiva.value = mp;
    modalForm.value = true;
}

function excluirMateriaPrima(mp) {
    if (!confirm(`Excluir "${mp.descMp}"? Se estiver em uso em algum orçamento, desative em vez de excluir.`)) return;

    router.delete(route('etiquetas.materiaPrima.destroy', mp.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Matéria-Prima de Etiqueta" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Matéria-Prima de Etiqueta">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="4" width="18" height="16" rx="1" />
                            <path d="M3 9h18" stroke-linecap="round" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Custo R$/m² por material — alimenta a calculadora de precificação de etiqueta nos orçamentos. Admin-only.
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[300px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Descrição, código, fabricante..."
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField
                            label="Status"
                            :model-value="filtros.status"
                            @update:model-value="(v) => { filtros.status = v; aplicarFiltros(); }"
                        >
                            <option value="todos">Todos</option>
                            <option value="ativa">Ativas</option>
                            <option value="inativa">Inativas</option>
                        </FilterField>

                        <button
                            type="button"
                            class="self-end rounded bg-teal px-3 py-1.5 text-xs font-medium text-white hover:bg-teal/90"
                            @click="novaMateriaPrima"
                        >
                            + Nova Matéria-Prima
                        </button>
                    </template>
                </PageHero>

                <DarkCard title="Materiais" :subtitle="`${materiasPrimas.total} registro${materiasPrimas.total !== 1 ? 's' : ''}`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <rect x="3" y="4" width="18" height="16" rx="1" />
                            <path d="M3 9h18" stroke-linecap="round" />
                        </svg>
                    </template>

                    <MateriasPrimaTabela
                        v-if="materiasPrimas.data.length"
                        :materias-primas="materiasPrimas.data"
                        @editar="editarMateriaPrima"
                        @excluir="excluirMateriaPrima"
                    />
                    <p v-else class="text-sm text-gray-400">Nenhuma matéria-prima cadastrada ainda.</p>

                    <div class="mt-4">
                        <Pagination :meta="materiasPrimas" :only="['materiasPrimas']" />
                    </div>
                </DarkCard>

                <MateriaPrimaFormModal :show="modalForm" :materia-prima="materiaPrimaAtiva" @close="modalForm = false" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
