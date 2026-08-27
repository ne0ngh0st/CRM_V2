<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import FilterField from '@/Components/FilterField.vue';
import KpiTile from '@/Components/KpiTile.vue';
import Modal from '@/Components/Modal.vue';
import FacaFormModal from '@/Components/Catalogo/FacaFormModal.vue';

const props = defineProps({
    role: String,
    podeGerenciar: Boolean,
    facas: Array,
    kpis: Object,
    filtros: Object,
    opcoes: Object,
});

const facaEmEdicaoId = ref(null);
const mostrarForm = ref(false);

// Guardamos o id, não o objeto: depois de adicionar/remover um recurso o Inertia
// recarrega `facas`, e um objeto capturado antes ficaria com a lista de imagens velha.
const facaEmEdicao = computed(
    () => props.facas.find((f) => f.id === facaEmEdicaoId.value) ?? null,
);

function novaFaca() {
    facaEmEdicaoId.value = null;
    mostrarForm.value = true;
}

function editarFaca(faca) {
    facaEmEdicaoId.value = faca.id;
    mostrarForm.value = true;
}

/** O modal já subiu as imagens; aqui só passamos a tratá-lo como edição da faca nova. */
function aoCadastrar(novoId) {
    facaEmEdicaoId.value = novoId;

    // A faca nova pode ter caído fora do filtro/busca ativos — aí ela não está em
    // `facas`, o modal acharia que ainda é cadastro e as imagens sumiriam da vista.
    if (novoId && !props.facas.some((f) => f.id === novoId)) {
        limparFiltros();
    }
}

function excluirFaca(faca) {
    const rotulo = `${faca.tipoNome} · item ${String(faca.item).padStart(2, '0')}`;
    if (!confirm(`Excluir a faca "${rotulo}"? As imagens enviadas por esta tela também serão apagadas.`)) {
        return;
    }
    router.delete(route('catalogo-facas.destroy', faca.id), { preserveScroll: true });
}

const filtros = reactive({
    busca: props.filtros.busca || '',
    tipo: props.filtros.tipo || '',
});

function aplicarFiltros() {
    router.get(route('catalogo-facas.index'), { ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['facas', 'kpis', 'filtros'],
    });
}

let timeoutBusca;
function onBuscaInput() {
    clearTimeout(timeoutBusca);
    timeoutBusca = setTimeout(aplicarFiltros, 300);
}

function limparFiltros() {
    Object.assign(filtros, { busca: '', tipo: '' });
    aplicarFiltros();
}

/** Um DarkCard por catálogo, na ordem que o controller já devolveu. */
const grupos = computed(() => {
    const mapa = new Map();
    for (const faca of props.facas) {
        if (!mapa.has(faca.tipo)) {
            mapa.set(faca.tipo, { tipo: faca.tipo, nome: faca.tipoNome, facas: [] });
        }
        mapa.get(faca.tipo).facas.push(faca);
    }
    return [...mapa.values()];
});

const zoom = ref(null);

function abrirZoom(faca, recurso) {
    zoom.value = {
        url: recurso.imagem,
        legenda: [
            `Item ${String(faca.item).padStart(2, '0')}`,
            `${faca.largura ?? '-'} x ${faca.altura ?? '-'} mm`,
            recurso.descricao,
        ].filter(Boolean).join(' · '),
    };
}
</script>

<template>
    <Head title="Catálogo de Facas" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-[1800px] flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <PageHero title="Catálogo de Facas">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M5 4l10 10M9 20l10-10" stroke-linecap="round" />
                            <circle cx="6.5" cy="17.5" r="2.5" />
                            <circle cx="17.5" cy="17.5" r="2.5" />
                        </svg>
                    </template>
                    <template #subtitle>
                        Medidas comerciais, recortes e observações das facas — consulta só leitura.
                    </template>
                    <template #meta>
                        <KpiTile :value="kpis.total" label="Facas" />
                        <KpiTile :value="kpis.catalogos" label="Catálogos" />
                        <KpiTile :value="kpis.filtradas" label="Exibindo" tone="ok" />
                    </template>
                    <template #filtros>
                        <div class="flex min-w-[200px] max-w-[300px] flex-1 flex-col gap-1">
                            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
                            <input
                                v-model="filtros.busca"
                                type="text"
                                placeholder="Ex.: 60x80 ou corte retangular"
                                class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                                @input="onBuscaInput"
                            />
                        </div>

                        <FilterField
                            label="Catálogo"
                            :model-value="filtros.tipo"
                            @update:model-value="(v) => { filtros.tipo = v; aplicarFiltros(); }"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="t in opcoes.tipos"
                                :key="t.slug"
                                :value="t.slug"
                            >
                                {{ t.nome }} ({{ t.total }})
                            </option>
                        </FilterField>

                        <button
                            type="button"
                            class="self-end rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
                            @click="limparFiltros"
                        >
                            Limpar filtros
                        </button>

                        <button
                            v-if="podeGerenciar"
                            type="button"
                            class="self-end rounded border border-teal bg-teal px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal/90"
                            @click="novaFaca"
                        >
                            + Nova faca
                        </button>
                    </template>
                </PageHero>

                <p v-if="!grupos.length" class="rounded border border-gray-300 bg-white p-6 text-sm text-gray-400">
                    Nenhuma faca encontrada com os filtros atuais.
                </p>

                <DarkCard
                    v-for="grupo in grupos"
                    :key="grupo.tipo"
                    :title="grupo.nome"
                    :subtitle="`${grupo.facas.length} faca${grupo.facas.length !== 1 ? 's' : ''} neste catálogo`"
                >
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M5 4l10 10M9 20l10-10" stroke-linecap="round" />
                            <circle cx="6.5" cy="17.5" r="2.5" />
                            <circle cx="17.5" cy="17.5" r="2.5" />
                        </svg>
                    </template>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <article
                            v-for="faca in grupo.facas"
                            :key="faca.id"
                            class="flex flex-col rounded border border-gray-200 bg-gray-50"
                        >
                            <header class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Item {{ String(faca.item).padStart(2, '0') }}
                                </span>
                                <div class="flex items-center gap-2">
                                    <div class="flex items-baseline gap-1 text-sm">
                                        <strong class="text-navy">{{ faca.largura ?? '-' }}</strong>
                                        <span class="text-gray-400">×</span>
                                        <strong class="text-navy">{{ faca.altura ?? '-' }}</strong>
                                        <span class="text-[0.65rem] text-gray-400">mm</span>
                                    </div>
                                    <div v-if="podeGerenciar" class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-400 hover:border-teal hover:text-teal"
                                            title="Editar faca e imagens"
                                            @click="editarFaca(faca)"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5">
                                                <path d="M4 20h4l10-10a2.8 2.8 0 10-4-4L4 16v4z" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-400 hover:border-red-500 hover:text-red-500"
                                            title="Excluir faca"
                                            @click="excluirFaca(faca)"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5">
                                                <path d="M5 7h14M10 7V5h4v2M6 7l1 13h10l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </header>

                            <div class="flex flex-1 flex-col gap-2 p-3">
                                <div
                                    v-if="faca.recursos.some((r) => r.imagem)"
                                    class="flex flex-wrap gap-2"
                                >
                                    <figure
                                        v-for="(recurso, i) in faca.recursos.filter((r) => r.imagem)"
                                        :key="`img-${i}`"
                                        class="flex w-20 flex-col items-center gap-1"
                                    >
                                        <button
                                            type="button"
                                            class="w-full rounded border border-gray-200 bg-white p-1 transition hover:border-cyan"
                                            :title="recurso.descricao || 'Ampliar'"
                                            @click="abrirZoom(faca, recurso)"
                                        >
                                            <img
                                                :src="recurso.imagem"
                                                :alt="recurso.descricao || `Faca item ${faca.item}`"
                                                class="h-16 w-full object-contain"
                                                loading="lazy"
                                            />
                                        </button>
                                        <figcaption
                                            v-if="recurso.descricao"
                                            class="text-center text-[0.6rem] leading-tight text-gray-500"
                                        >
                                            {{ recurso.descricao }}
                                        </figcaption>
                                    </figure>
                                </div>

                                <div
                                    v-if="faca.recursos.some((r) => !r.imagem)"
                                    class="flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="(recurso, i) in faca.recursos.filter((r) => !r.imagem)"
                                        :key="`txt-${i}`"
                                        class="rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[0.65rem] text-gray-600"
                                    >
                                        {{ recurso.descricao }}
                                    </span>
                                </div>

                                <p
                                    v-if="faca.observacao"
                                    class="mt-auto border-t border-gray-200 pt-2 text-[0.68rem] leading-snug text-amber-700"
                                >
                                    {{ faca.observacao }}
                                </p>
                            </div>
                        </article>
                    </div>
                </DarkCard>
            </div>
        </div>

        <FacaFormModal
            v-if="podeGerenciar"
            :show="mostrarForm"
            :faca="facaEmEdicao"
            :tipos="opcoes.todosTipos"
            :tipo-sugerido="filtros.tipo"
            @cadastrada="aoCadastrar"
            @close="mostrarForm = false"
        />

        <Modal :show="zoom !== null" max-width="2xl" @close="zoom = null">
            <div v-if="zoom" class="p-4">
                <img :src="zoom.url" :alt="zoom.legenda" class="mx-auto max-h-[70vh] w-auto object-contain" />
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-gray-200 pt-3">
                    <strong class="text-sm text-gray-700">{{ zoom.legenda }}</strong>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
                        @click="zoom = null"
                    >
                        Fechar
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
