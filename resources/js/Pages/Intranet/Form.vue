<script setup>
/**
 * Intranet — nova publicação / editar publicação.
 *
 * ANEXOS, UM POR REQUISIÇÃO. Produção limita o corpo a 16 MB (`post_max_size` e
 * `client_max_body_size`); três PDFs num POST só estourariam com um 413 do nginx, sem
 * mensagem nenhuma. Por isso:
 *   - na publicação NOVA, os arquivos escolhidos ficam numa fila local e sobem um a um
 *     logo depois do "Publicar" — mesmo desenho do Catálogo de Facas, inclusive o
 *     `flash.recursoCriadoId` para saber o id recém-criado;
 *   - na EDIÇÃO, cada arquivo sobe na hora em que é escolhido.
 *
 * ⚠️ O `post` de criar usa `preserveState: true`. Sem ele o Inertia remontaria esta página
 * ao voltar do `back()` do servidor e a fila de anexos se perderia antes de subir.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import IconeNav from '@/Components/Icones/IconeNav.vue';
import { useConfirmacao } from '@/composables/useConfirmacao.js';
import { tamanhoArquivo } from '@/utils/formato.js';

const props = defineProps({
    /** null = nova publicação. */
    publicacao: { type: Object, default: null },
    categorias: { type: Array, required: true },
    limites: { type: Object, required: true },
});

const page = usePage();
const editando = computed(() => props.publicacao !== null);

const categoriaInicial = props.publicacao?.categoria ?? 'aviso';

const form = useForm({
    categoria: categoriaInicial,
    titulo: props.publicacao?.titulo ?? '',
    corpo: props.publicacao?.corpo ?? '',
    importante: props.publicacao?.importante ?? false,
    fixada: props.publicacao?.fixada ?? false,
    exige_ciencia: props.publicacao?.exigeCiencia
        ?? props.categorias.find((c) => c.valor === categoriaInicial)?.exigeCiencia
        ?? false,
    pedir_ciencia_de_novo: false,
});

/**
 * Trocar a categoria aplica o PADRÃO de ciência dela (regra de negócio pede, o resto não).
 * Só na publicação nova: editando, a escolha de quem publicou já foi feita e trocar a
 * categoria não pode desligar em silêncio uma ciência que a equipe está dando.
 */
function aoTrocarCategoria() {
    if (editando.value) return;

    form.exige_ciencia = props.categorias.find((c) => c.valor === form.categoria)?.exigeCiencia ?? false;
}

// ── Escrever / Pré-visualizar ────────────────────────────────────────────────────────
const aba = ref('escrever');
const previaHtml = ref('');
const carregandoPrevia = ref(false);

/** A prévia usa o MESMO renderizador da leitura (servidor), senão ela mentiria. */
async function mostrarPrevia() {
    aba.value = 'previa';
    carregandoPrevia.value = true;
    try {
        const { data } = await axios.post(route('intranet.previa'), { corpo: form.corpo });
        previaHtml.value = data.html;
    } catch {
        previaHtml.value = '<p>Não foi possível gerar a pré-visualização.</p>';
    } finally {
        carregandoPrevia.value = false;
    }
}

// ── Anexos ───────────────────────────────────────────────────────────────────────────
const inputArquivo = ref(null);
const pendentes = ref([]);
const enviando = ref(false);
const erroAnexo = ref('');
const limiteBytes = props.limites.tamanhoMaximoMb * 1024 * 1024;

const anexosSalvos = computed(() => props.publicacao?.anexos ?? []);
const totalAnexos = computed(() => anexosSalvos.value.length + pendentes.value.length);

function limparInput() {
    if (inputArquivo.value) inputArquivo.value.value = '';
}

function enviarAnexo(publicacaoId, arquivo) {
    return new Promise((resolve) => {
        let erro = null;
        router.post(
            route('intranet.anexos.store', publicacaoId),
            { arquivo },
            {
                preserveScroll: true,
                preserveState: true,
                forceFormData: true,
                // onFinish, não onSuccess: o `back()` do storeAnexo com preserveState
                // nem sempre dispara onSuccess — a fila de anexos travava no primeiro
                // arquivo (mesmo desenho do Catálogo de Facas).
                onError: (erros) => {
                    erro = erros.arquivo ?? 'Falha ao enviar.';
                },
                onFinish: () => resolve(erro),
            },
        );
    });
}

async function aoEscolherArquivos(evento) {
    erroAnexo.value = '';
    const arquivos = [...(evento.target.files ?? [])];
    limparInput();

    // Barra aqui o que o servidor recusaria de qualquer jeito — e o que o nginx recusaria
    // SEM mensagem (acima de 16 MB a requisição nem chega ao Laravel).
    const grandes = arquivos.filter((a) => a.size > limiteBytes);
    if (grandes.length) {
        erroAnexo.value = `Acima de ${props.limites.tamanhoMaximoMb} MB: ${grandes.map((a) => a.name).join(', ')}.`;
    }
    const validos = arquivos.filter((a) => a.size <= limiteBytes)
        .slice(0, Math.max(0, props.limites.maxAnexos - totalAnexos.value));

    if (!editando.value) {
        pendentes.value.push(...validos.map((arquivo) => ({ arquivo })));
        return;
    }

    enviando.value = true;
    for (const arquivo of validos) {
        const erro = await enviarAnexo(props.publicacao.id, arquivo);
        if (erro) erroAnexo.value = `${arquivo.name}: ${erro}`;
    }
    enviando.value = false;
}

const { confirmacao, confirmar, aoConfirmar, aoCancelar } = useConfirmacao();

async function removerAnexo(anexo) {
    const ok = await confirmar({
        titulo: 'Remover anexo',
        subtitulo: anexo.nome,
        mensagem: 'O arquivo sai da publicação e do servidor.',
        rotuloConfirmar: 'Remover',
        tom: 'danger',
    });
    if (!ok) return;

    router.delete(route('intranet.anexos.destroy', anexo.id), { preserveScroll: true, preserveState: true });
}

// ── Salvar ───────────────────────────────────────────────────────────────────────────
function salvar() {
    if (editando.value) {
        form.put(route('intranet.update', props.publicacao.id));
        return;
    }

    form.post(route('intranet.store'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: async () => {
            const novoId = page.props.flash?.recursoCriadoId ?? null;
            if (!novoId) return;

            enviando.value = true;
            const falhas = [];
            for (const { arquivo } of pendentes.value) {
                const erro = await enviarAnexo(novoId, arquivo);
                if (erro) falhas.push(`${arquivo.name}: ${erro}`);
            }
            enviando.value = false;
            pendentes.value = [];

            // Se algum anexo falhou, cai na EDIÇÃO: lá dá para reenviar. Indo direto para a
            // leitura, a falha passaria despercebida.
            router.visit(falhas.length ? route('intranet.edit', novoId) : route('intranet.show', novoId));
        },
    });
}

const ocupado = computed(() => form.processing || enviando.value);
</script>

<template>
    <Head :title="editando ? 'Editar publicação' : 'Nova publicação'" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <Link
                    :href="editando ? route('intranet.show', publicacao.id) : route('intranet.index')"
                    class="inline-flex w-fit items-center gap-1 text-xs font-medium text-intranet-dark hover:underline"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                        <path d="M19 12H5M11 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ editando ? 'Voltar à publicação' : 'Intranet' }}
                </Link>

                <form class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm" @submit.prevent="salvar">
                    <header class="flex items-center gap-2 border-b border-intranet/40 border-l-4 border-l-intranet-dark bg-intranet/15 px-4 py-3 sm:px-6">
                        <span class="h-5 w-5 text-intranet-dark"><IconeNav nome="intranet" /></span>
                        <h1 class="text-base font-bold text-gray-900">{{ editando ? 'Editar publicação' : 'Nova publicação' }}</h1>
                    </header>

                    <div class="flex flex-col gap-4 px-4 py-5 sm:px-6">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-[220px_1fr]">
                            <div class="flex flex-col gap-1">
                                <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500" for="categoria">Categoria</label>
                                <select
                                    id="categoria"
                                    v-model="form.categoria"
                                    class="min-h-11 rounded border-gray-300 text-sm focus:border-intranet-dark focus:ring-intranet-dark sm:min-h-0"
                                    @change="aoTrocarCategoria"
                                >
                                    <option v-for="c in categorias" :key="c.valor" :value="c.valor">{{ c.rotulo }}</option>
                                </select>
                                <InputError :message="form.errors.categoria" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500" for="titulo">Título</label>
                                <input
                                    id="titulo"
                                    v-model="form.titulo"
                                    type="text"
                                    maxlength="160"
                                    placeholder="Ex.: Nova regra de desconto para supermercados"
                                    class="min-h-11 rounded border-gray-300 text-sm focus:border-intranet-dark focus:ring-intranet-dark sm:min-h-0"
                                />
                                <InputError :message="form.errors.titulo" />
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="flex items-end justify-between gap-2">
                                <span class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Texto</span>
                                <div class="flex overflow-hidden rounded border border-gray-300 text-xs">
                                    <button
                                        type="button"
                                        class="px-3 py-1"
                                        :class="aba === 'escrever' ? 'bg-intranet-dark text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                                        @click="aba = 'escrever'"
                                    >
                                        Escrever
                                    </button>
                                    <button
                                        type="button"
                                        class="px-3 py-1"
                                        :class="aba === 'previa' ? 'bg-intranet-dark text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                                        @click="mostrarPrevia"
                                    >
                                        Pré-visualizar
                                    </button>
                                </div>
                            </div>
                            <textarea
                                v-show="aba === 'escrever'"
                                v-model="form.corpo"
                                rows="14"
                                class="rounded border-gray-300 font-mono text-sm leading-relaxed focus:border-intranet-dark focus:ring-intranet-dark"
                                placeholder="Escreva o aviso, a regra ou o passo a passo."
                            />
                            <div
                                v-show="aba === 'previa'"
                                class="min-h-[14rem] rounded border border-dashed border-intranet bg-white px-4 py-3"
                            >
                                <p v-if="carregandoPrevia" class="text-xs text-gray-400">Gerando pré-visualização…</p>
                                <!-- eslint-disable-next-line vue/no-v-html -- HTML sanitizado no servidor -->
                                <div v-else class="intranet-corpo" v-html="previaHtml" />
                            </div>
                            <p class="text-[0.68rem] leading-relaxed text-gray-400">
                                Formatação: <code>**negrito**</code> · <code>## Título</code> · linhas começando com
                                <code>-</code> viram lista e com <code>1.</code> viram passo a passo · <code>[texto](https://link)</code>.
                            </p>
                            <InputError :message="form.errors.corpo" />
                        </div>

                        <fieldset class="flex flex-col gap-2 rounded border border-gray-200 bg-gray-50 px-3 py-3">
                            <legend class="px-1 text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Opções</legend>
                            <label class="flex items-start gap-2 text-sm text-gray-700">
                                <input v-model="form.importante" type="checkbox" class="mt-0.5 rounded border-gray-300 text-intranet-dark focus:ring-intranet-dark" />
                                <span><strong class="font-semibold">Importante</strong> — a notificação chega destacada no sino de todos.</span>
                            </label>
                            <label class="flex items-start gap-2 text-sm text-gray-700">
                                <input v-model="form.fixada" type="checkbox" class="mt-0.5 rounded border-gray-300 text-intranet-dark focus:ring-intranet-dark" />
                                <span><strong class="font-semibold">Fixar no topo</strong> da lista da intranet.</span>
                            </label>
                            <label class="flex items-start gap-2 text-sm text-gray-700">
                                <input v-model="form.exige_ciencia" type="checkbox" class="mt-0.5 rounded border-gray-300 text-intranet-dark focus:ring-intranet-dark" />
                                <span><strong class="font-semibold">Pedir ciência</strong> — cada pessoa confirma "li e estou ciente", e você vê quem falta.</span>
                            </label>
                            <label
                                v-if="editando && form.exige_ciencia"
                                class="ml-6 flex items-start gap-2 rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-sm text-amber-800"
                            >
                                <input v-model="form.pedir_ciencia_de_novo" type="checkbox" class="mt-0.5 rounded border-amber-400 text-amber-dark focus:ring-amber" />
                                <span>
                                    <strong class="font-semibold">Mudança relevante: pedir ciência de novo.</strong>
                                    Zera as confirmações e avisa todo mundo. Use quando a regra mudou — sem isso,
                                    quem confirmou a versão antiga continua como "ciente".
                                </span>
                            </label>
                        </fieldset>

                        <section class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">
                                    Anexos ({{ totalAnexos }}/{{ limites.maxAnexos }})
                                </span>
                                <label
                                    class="inline-flex min-h-11 cursor-pointer items-center gap-1.5 rounded border border-intranet-dark bg-white px-3 py-1 text-xs font-semibold text-intranet-dark hover:bg-intranet/15 sm:min-h-0"
                                    :class="{ 'pointer-events-none opacity-50': enviando || totalAnexos >= limites.maxAnexos }"
                                >
                                    <input
                                        ref="inputArquivo"
                                        type="file"
                                        multiple
                                        class="sr-only"
                                        :accept="limites.aceitos"
                                        @change="aoEscolherArquivos"
                                    />
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                                        <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                                    </svg>
                                    Adicionar arquivos
                                </label>
                            </div>
                            <p class="text-[0.68rem] text-gray-400">
                                PDF, imagem, Word, Excel ou PowerPoint · até {{ limites.tamanhoMaximoMb }} MB cada.
                                Imagens aparecem na própria publicação — bom para fluxogramas de workflow.
                            </p>
                            <p v-if="erroAnexo" class="text-xs text-red-600">{{ erroAnexo }}</p>
                            <p v-if="enviando" class="text-xs text-intranet-dark">Enviando anexos…</p>

                            <ul v-if="totalAnexos" class="divide-y divide-gray-100 rounded border border-gray-200">
                                <li v-for="a in anexosSalvos" :key="`s-${a.id}`" class="flex items-center gap-3 px-3 py-2 text-sm">
                                    <span class="min-w-0 flex-1 truncate text-gray-700">{{ a.nome }}</span>
                                    <span class="shrink-0 text-xs text-gray-400">{{ tamanhoArquivo(a.tamanho) }}</span>
                                    <button type="button" class="tbl-acao tbl-acao-danger" title="Remover anexo" @click="removerAnexo(a)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M6 7h12M9 7V5h6v2m-8 0 1 12h8l1-12" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </li>
                                <li v-for="(p, i) in pendentes" :key="`p-${i}`" class="flex items-center gap-3 px-3 py-2 text-sm">
                                    <span class="min-w-0 flex-1 truncate text-gray-700">{{ p.arquivo.name }}</span>
                                    <span class="shrink-0 text-[0.62rem] font-semibold uppercase text-intranet-dark">sobe ao publicar</span>
                                    <span class="shrink-0 text-xs text-gray-400">{{ tamanhoArquivo(p.arquivo.size) }}</span>
                                    <button type="button" class="tbl-acao tbl-acao-danger" title="Tirar da lista" @click="pendentes.splice(i, 1)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                        <Link
                            :href="editando ? route('intranet.show', publicacao.id) : route('intranet.index')"
                            class="inline-flex min-h-11 items-center justify-center rounded border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 sm:min-h-0"
                        >
                            Cancelar
                        </Link>
                        <button
                            type="submit"
                            :disabled="ocupado"
                            class="inline-flex min-h-11 items-center justify-center rounded bg-intranet-dark px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-navy disabled:opacity-60 sm:min-h-0"
                        >
                            {{ enviando ? 'Enviando anexos…' : (editando ? 'Salvar alterações' : 'Publicar e avisar a equipe') }}
                        </button>
                    </footer>
                </form>
            </div>
        </div>

        <ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
    </AuthenticatedLayout>
</template>
