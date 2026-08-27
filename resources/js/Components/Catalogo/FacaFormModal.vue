<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const page = usePage();

const props = defineProps({
    show: Boolean,
    /** null = cadastrar nova; objeto = editar (vem sempre fresco da página) */
    faca: { type: Object, default: null },
    tipos: { type: Array, default: () => [] },
    /** catálogo filtrado na tela, usado como padrão ao cadastrar */
    tipoSugerido: { type: String, default: '' },
});

const emit = defineEmits(['close', 'cadastrada']);

const editando = computed(() => props.faca !== null);

const form = useForm({
    tipo: '',
    item: '',
    largura: '',
    altura: '',
    observacao: '',
});

// Recurso é salvo por request próprio (tem upload de arquivo), separado do form da faca.
const recursoForm = useForm({
    descricao: '',
    imagem: null,
});

const inputArquivo = ref(null);

/**
 * Fila de imagens escolhidas ANTES da faca existir (modo cadastro). O endpoint de
 * upload precisa do id da faca, então elas ficam aqui e sobem logo depois do
 * "Cadastrar faca" — pro usuário é uma operação só.
 */
const pendentes = ref([]);
const enviandoPendentes = ref(false);

function limparPendentes() {
    pendentes.value.forEach((p) => URL.revokeObjectURL(p.preview));
    pendentes.value = [];
}

function preencher() {
    form.clearErrors();
    recursoForm.clearErrors();
    recursoForm.reset();
    limparPendentes();
    if (inputArquivo.value) {
        inputArquivo.value.value = '';
    }

    if (props.faca) {
        form.tipo = props.faca.tipo;
        form.item = props.faca.item;
        form.largura = props.faca.largura ?? '';
        form.altura = props.faca.altura ?? '';
        form.observacao = props.faca.observacao ?? '';
    } else {
        form.reset();
        form.tipo = props.tipoSugerido || props.tipos[0]?.slug || '';
    }
}

watch(() => props.show, (aberto) => {
    if (aberto) {
        preencher();
    }
});

function salvar() {
    if (editando.value) {
        form.patch(route('catalogo-facas.update', props.faca.id), { preserveScroll: true });

        return;
    }

    form.post(route('catalogo-facas.store'), {
        preserveScroll: true,
        onSuccess: async () => {
            // O id vem do servidor (flash), não de procurar a faca na listagem — ela
            // pode estar fora do filtro/busca ativos e simplesmente não aparecer lá.
            const novoId = page.props.flash?.recursoCriadoId ?? null;

            if (novoId && pendentes.value.length) {
                await enviarPendentes(novoId);
            }

            // Deixa o modal aberto na faca nova pra conferir/ajustar o que subiu.
            emit('cadastrada', novoId);

            if (!novoId) {
                emit('close');
            }
        },
    });
}

/** Sobe a fila em sequência — cada upload é um request próprio (multipart). */
async function enviarPendentes(facaId) {
    enviandoPendentes.value = true;

    for (const pendente of [...pendentes.value]) {
        await new Promise((resolve) => {
            router.post(
                route('catalogo-facas.recursos.store', facaId),
                { descricao: pendente.descricao || '', imagem: pendente.arquivo },
                { preserveScroll: true, forceFormData: true, onFinish: resolve },
            );
        });
    }

    limparPendentes();
    enviandoPendentes.value = false;
}

function adicionarRecurso() {
    // Sem faca ainda: enfileira e mostra na hora, sem ida ao servidor.
    if (!editando.value) {
        if (!recursoForm.imagem && !recursoForm.descricao) {
            recursoForm.setError('descricao', 'Informe uma descrição ou escolha uma imagem.');

            return;
        }

        pendentes.value.push({
            descricao: recursoForm.descricao,
            arquivo: recursoForm.imagem,
            preview: recursoForm.imagem ? URL.createObjectURL(recursoForm.imagem) : null,
        });
        recursoForm.reset();
        recursoForm.clearErrors();
        if (inputArquivo.value) {
            inputArquivo.value.value = '';
        }

        return;
    }

    recursoForm.post(route('catalogo-facas.recursos.store', props.faca.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            recursoForm.reset();
            if (inputArquivo.value) {
                inputArquivo.value.value = '';
            }
        },
    });
}

function removerPendente(indice) {
    URL.revokeObjectURL(pendentes.value[indice].preview);
    pendentes.value.splice(indice, 1);
}

function removerRecurso(recurso) {
    if (!confirm('Remover esta imagem/descrição da faca?')) {
        return;
    }
    router.post(route('catalogo-facas.recursos.destroy', recurso.id), {}, { preserveScroll: true });
}

function onArquivo(evento) {
    recursoForm.imagem = evento.target.files[0] ?? null;
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-5">
            <h2 class="text-base font-semibold text-gray-800">
                {{ editando ? 'Editar faca' : 'Nova faca' }}
            </h2>
            <p class="mt-1 text-xs text-gray-500">
                {{ editando
                    ? 'Altere as medidas ou gerencie as imagens e recortes desta faca.'
                    : 'Cadastre a faca primeiro; depois de salvar, dá pra anexar as imagens.' }}
            </p>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Catálogo</label>
                    <select
                        v-model="form.tipo"
                        class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    >
                        <option v-for="t in tipos" :key="t.slug" :value="t.slug">{{ t.nome }}</option>
                    </select>
                    <p v-if="form.errors.tipo" class="text-[0.68rem] text-red-600">{{ form.errors.tipo }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Item nº</label>
                    <input
                        v-model="form.item"
                        type="number"
                        min="1"
                        class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    />
                    <p v-if="form.errors.item" class="text-[0.68rem] text-red-600">{{ form.errors.item }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Largura (mm)</label>
                    <input
                        v-model="form.largura"
                        type="text"
                        placeholder="Ex.: 40, 0/160 ou Ø 40"
                        class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    />
                    <p v-if="form.errors.largura" class="text-[0.68rem] text-red-600">{{ form.errors.largura }}</p>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Altura (mm)</label>
                    <input
                        v-model="form.altura"
                        type="text"
                        placeholder="Ex.: 25"
                        class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    />
                    <p v-if="form.errors.altura" class="text-[0.68rem] text-red-600">{{ form.errors.altura }}</p>
                </div>

                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Observação</label>
                    <textarea
                        v-model="form.observacao"
                        rows="2"
                        placeholder="Ex.: Para essa faca só temos na versão com picote."
                        class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    />
                    <p v-if="form.errors.observacao" class="text-[0.68rem] text-red-600">{{ form.errors.observacao }}</p>
                </div>
            </div>

            <div class="mt-5 border-t border-gray-200 pt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                    Imagens e recortes
                </h3>
                <p v-if="!editando" class="mt-1 text-[0.68rem] text-gray-500">
                    Escolha as imagens agora — elas sobem sozinhas assim que você cadastrar a faca.
                </p>

                <!-- Fila do modo cadastro: ainda não existe id pra anexar no servidor. -->
                <div v-if="pendentes.length" class="mt-2 flex flex-wrap gap-2">
                    <figure
                        v-for="(pendente, i) in pendentes"
                        :key="`pendente-${i}`"
                        class="relative flex w-24 flex-col items-center gap-1 rounded border border-dashed border-teal/50 bg-teal/5 p-2"
                    >
                        <img
                            v-if="pendente.preview"
                            :src="pendente.preview"
                            alt="Prévia da imagem"
                            class="h-14 w-full object-contain"
                        />
                        <span v-else class="flex h-14 items-center text-[0.6rem] text-gray-400">sem imagem</span>
                        <figcaption class="text-center text-[0.6rem] leading-tight text-gray-600">
                            {{ pendente.descricao || '—' }}
                        </figcaption>
                        <span class="text-[0.55rem] uppercase tracking-wide text-teal">na fila</span>
                        <button
                            type="button"
                            class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-400 hover:border-red-500 hover:text-red-500"
                            title="Tirar da fila"
                            @click="removerPendente(i)"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3">
                                <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                            </svg>
                        </button>
                    </figure>
                </div>

                <div v-if="editando && faca.recursos.length" class="mt-2 flex flex-wrap gap-2">
                    <figure
                        v-for="recurso in faca.recursos"
                        :key="recurso.id"
                        class="relative flex w-24 flex-col items-center gap-1 rounded border border-gray-200 bg-gray-50 p-2"
                    >
                        <img
                            v-if="recurso.imagem"
                            :src="recurso.imagem"
                            :alt="recurso.descricao || 'Imagem da faca'"
                            class="h-14 w-full object-contain"
                        />
                        <span v-else class="flex h-14 items-center text-[0.6rem] text-gray-400">sem imagem</span>
                        <figcaption class="text-center text-[0.6rem] leading-tight text-gray-600">
                            {{ recurso.descricao || '—' }}
                        </figcaption>
                        <button
                            type="button"
                            class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-400 hover:border-red-500 hover:text-red-500"
                            title="Remover"
                            @click="removerRecurso(recurso)"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-3 w-3">
                                <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                            </svg>
                        </button>
                    </figure>
                </div>
                <p
                    v-else-if="!pendentes.length"
                    class="mt-2 text-xs text-gray-400"
                >
                    Nenhuma imagem anexada ainda.
                </p>

                <div class="mt-3 flex flex-wrap items-end gap-2 rounded border border-gray-200 bg-gray-50 p-3">
                    <div class="flex min-w-[180px] flex-1 flex-col gap-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                            Descrição do recorte
                        </label>
                        <input
                            v-model="recursoForm.descricao"
                            type="text"
                            placeholder="Ex.: Corte retangular"
                            class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                        />
                    </div>
                    <div class="flex min-w-[200px] flex-1 flex-col gap-1">
                        <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                            Imagem (JPG, PNG, GIF ou WEBP · até 5 MB)
                        </label>
                        <input
                            ref="inputArquivo"
                            type="file"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            class="w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-gray-200 file:px-2 file:py-1 file:text-xs file:text-gray-700"
                            @change="onArquivo"
                        />
                    </div>
                    <button
                        type="button"
                        class="rounded border border-teal bg-teal px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal/90 disabled:opacity-50"
                        :disabled="recursoForm.processing"
                        @click="adicionarRecurso"
                    >
                        <template v-if="recursoForm.processing">Enviando...</template>
                        <template v-else-if="editando">Adicionar</template>
                        <template v-else>Adicionar à fila</template>
                    </button>
                </div>
                <p v-if="recursoForm.errors.imagem" class="mt-1 text-[0.68rem] text-red-600">
                    {{ recursoForm.errors.imagem }}
                </p>
                <p v-if="recursoForm.errors.descricao" class="mt-1 text-[0.68rem] text-red-600">
                    {{ recursoForm.errors.descricao }}
                </p>
            </div>

            <div class="mt-5 flex justify-end gap-2 border-t border-gray-200 pt-4">
                <button
                    type="button"
                    class="rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100"
                    @click="emit('close')"
                >
                    {{ editando ? 'Fechar' : 'Cancelar' }}
                </button>
                <button
                    type="button"
                    class="rounded border border-teal bg-teal px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal/90 disabled:opacity-50"
                    :disabled="form.processing || enviandoPendentes"
                    @click="salvar"
                >
                    <template v-if="enviandoPendentes">Enviando imagens...</template>
                    <template v-else-if="form.processing">Salvando...</template>
                    <template v-else-if="editando">Salvar alterações</template>
                    <template v-else-if="pendentes.length">
                        Cadastrar faca e enviar {{ pendentes.length }} imagem{{ pendentes.length > 1 ? 'ns' : '' }}
                    </template>
                    <template v-else>Cadastrar faca</template>
                </button>
            </div>
        </div>
    </Modal>
</template>
