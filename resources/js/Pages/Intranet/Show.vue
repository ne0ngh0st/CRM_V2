<script setup>
/**
 * Intranet — leitura de uma publicação.
 *
 * Abrir esta página registra a leitura no servidor (é o que zera o "Novo" e o contador da
 * faixa do Painel). O "Li e estou ciente" é outra coisa, e é explícito: ler uma regra não
 * é concordar com ela.
 *
 * ⚠️ `corpoHtml` chega PRONTO do servidor, já sanitizado (`html_input => strip`, ver
 * `IntranetPublicacao::renderizar`). É isso que torna seguro o `v-html` abaixo. Não trocar
 * por um parser de markdown no front: a regra de segurança passaria a morar em dois
 * lugares, e o `v-html` num deles injetaria o que o outro deixou passar.
 */
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DarkCard from '@/Components/DarkCard.vue';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import SeloIntranet from '@/Components/Intranet/SeloIntranet.vue';
import { useConfirmacao } from '@/composables/useConfirmacao.js';
import { dataCurta, dataHora, tamanhoArquivo } from '@/utils/formato.js';

const props = defineProps({
    publicacao: { type: Object, required: true },
    pode: { type: Object, required: true },
    ciencia: { type: Object, default: null },
    leituras: { type: Number, default: null },
});

const registrando = ref(false);

function darCiencia() {
    registrando.value = true;
    router.post(route('intranet.ciente', props.publicacao.id), {}, {
        preserveScroll: true,
        onFinish: () => { registrando.value = false; },
    });
}

const { confirmacao, confirmar, aoConfirmar, aoCancelar } = useConfirmacao();

async function excluir() {
    const ok = await confirmar({
        titulo: 'Excluir publicação',
        subtitulo: props.publicacao.titulo,
        mensagem: 'A publicação sai da intranet para todo mundo.',
        detalhe: 'O registro de quem leu e de quem deu ciência é preservado.',
        rotuloConfirmar: 'Excluir',
        tom: 'danger',
    });
    if (!ok) return;

    router.delete(route('intranet.destroy', props.publicacao.id));
}

const imagemAberta = ref(null);
const pendentesAbertos = ref(false);
</script>

<template>
    <Head :title="publicacao.titulo" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 px-3 sm:px-4 lg:px-6">
                <Link
                    :href="route('intranet.index')"
                    class="inline-flex w-fit items-center gap-1 text-xs font-medium text-intranet-dark hover:underline"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                        <path d="M19 12H5M11 6l-6 6 6 6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Intranet
                </Link>

                <article class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
                    <!-- Cabeçalho no tint da seção, com o filete cheio: é a "cara" da intranet. -->
                    <header class="border-b border-intranet/40 border-l-4 border-l-intranet-dark bg-intranet/15 px-4 py-4 sm:px-6">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <SeloIntranet>{{ publicacao.categoriaRotulo }}</SeloIntranet>
                            <SeloIntranet v-if="publicacao.importante" tom="importante">Importante</SeloIntranet>
                            <SeloIntranet v-if="publicacao.fixada" tom="fixada">Fixada</SeloIntranet>
                        </div>
                        <h1 class="mt-2 text-xl font-bold leading-tight text-gray-900">{{ publicacao.titulo }}</h1>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ publicacao.autor }} · {{ dataCurta(publicacao.publicadaEm) }}
                            <template v-if="publicacao.editadaEm"> · editada em {{ dataCurta(publicacao.editadaEm) }}</template>
                            <template v-if="leituras !== null"> · lida por {{ leituras }} {{ leituras === 1 ? 'pessoa' : 'pessoas' }}</template>
                        </p>

                        <div v-if="pode.gerenciar" class="mt-3 flex flex-wrap gap-2">
                            <Link
                                :href="route('intranet.edit', publicacao.id)"
                                class="inline-flex min-h-11 items-center rounded border border-intranet-dark bg-white px-3 py-1 text-xs font-semibold text-intranet-dark hover:bg-intranet/15 sm:min-h-0"
                            >
                                Editar
                            </Link>
                            <button
                                type="button"
                                class="inline-flex min-h-11 items-center rounded border border-red-300 bg-white px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50 sm:min-h-0"
                                @click="excluir"
                            >
                                Excluir
                            </button>
                        </div>
                    </header>

                    <!-- eslint-disable-next-line vue/no-v-html -- HTML sanitizado no servidor -->
                    <div class="intranet-corpo px-4 py-5 sm:px-6" v-html="publicacao.corpoHtml" />

                    <section v-if="publicacao.imagens.length" class="border-t border-gray-100 px-4 py-4 sm:px-6">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Imagens</h2>
                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <button
                                v-for="img in publicacao.imagens"
                                :key="img.id"
                                type="button"
                                class="group overflow-hidden rounded border border-gray-200 bg-gray-50 text-left hover:border-intranet"
                                :title="`Ampliar ${img.nome}`"
                                @click="imagemAberta = img"
                            >
                                <img :src="img.url" :alt="img.nome" class="max-h-72 w-full object-contain" loading="lazy" />
                                <span class="block truncate border-t border-gray-200 px-2 py-1 text-[0.68rem] text-gray-500">{{ img.nome }}</span>
                            </button>
                        </div>
                    </section>

                    <section v-if="publicacao.arquivos.length" class="border-t border-gray-100 px-4 py-4 sm:px-6">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Anexos</h2>
                        <ul class="mt-2 divide-y divide-gray-100 rounded border border-gray-200">
                            <li v-for="a in publicacao.arquivos" :key="a.id">
                                <!-- ⚠️ `<a>` e não `<Link>`: baixar é navegação do navegador. Pelo
                                     Inertia o XHR receberia o binário e o clique não faria nada. -->
                                <a
                                    :href="a.download"
                                    class="flex min-h-11 items-center gap-3 px-3 py-2 text-sm hover:bg-intranet/10"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4 shrink-0 text-intranet-dark">
                                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z" stroke-linejoin="round" />
                                        <path d="M14 3v5h5M12 11v6m0 0-2.5-2.5M12 17l2.5-2.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <span class="min-w-0 flex-1 truncate text-gray-700">{{ a.nome }}</span>
                                    <span class="shrink-0 text-xs text-gray-400">{{ tamanhoArquivo(a.tamanho) }}</span>
                                </a>
                            </li>
                        </ul>
                    </section>

                    <!-- A confirmação de leitura: só quando a publicação pede. -->
                    <footer
                        v-if="publicacao.exigeCiencia"
                        class="flex flex-col gap-2 border-t border-intranet/40 bg-intranet/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                    >
                        <p v-if="publicacao.cienteEm" class="text-sm text-green-700">
                            <strong class="font-semibold">Você deu ciência</strong> em {{ dataHora(publicacao.cienteEm) }}.
                        </p>
                        <p v-else class="text-sm text-gray-700">
                            Esta publicação pede que você confirme a leitura.
                        </p>
                        <button
                            v-if="!publicacao.cienteEm && pode.darCiencia"
                            type="button"
                            :disabled="registrando"
                            class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded bg-intranet-dark px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-navy disabled:opacity-60"
                            @click="darCiencia"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                                <path d="m5 12 5 5 9-10" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Li e estou ciente
                        </button>
                        <p v-else-if="!publicacao.cienteEm" class="text-xs text-gray-500">
                            Em simulação de usuário a ciência não pode ser dada.
                        </p>
                    </footer>
                </article>

                <!-- Painel de ciência: só para quem gerencia a publicação. -->
                <DarkCard
                    v-if="ciencia"
                    title="Ciência da equipe"
                    :subtitle="`${ciencia.cientes} de ${ciencia.total} confirmaram a leitura`"
                >
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="m5 12 5 5 9-10" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>

                    <div class="px-4 py-3">
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                            <div
                                class="h-full rounded-full bg-intranet-dark transition-all"
                                :style="{ width: ciencia.total ? `${(ciencia.cientes / ciencia.total) * 100}%` : '0%' }"
                            />
                        </div>

                        <p v-if="ciencia.pendentes.length === 0" class="mt-3 text-sm text-green-700">
                            Todo mundo já deu ciência.
                        </p>
                        <template v-else>
                            <button
                                type="button"
                                class="mt-3 text-xs font-semibold text-intranet-dark hover:underline"
                                @click="pendentesAbertos = !pendentesAbertos"
                            >
                                {{ pendentesAbertos ? 'Esconder' : 'Ver' }} quem ainda não deu ciência ({{ ciencia.pendentes.length }})
                            </button>
                            <ul v-if="pendentesAbertos" class="mt-2 grid grid-cols-1 gap-x-4 gap-y-0.5 text-xs text-gray-600 sm:grid-cols-2 lg:grid-cols-3">
                                <li v-for="(nome, i) in ciencia.pendentes" :key="i" class="truncate">{{ nome }}</li>
                            </ul>
                        </template>
                    </div>
                </DarkCard>
            </div>
        </div>

        <!-- Imagem ampliada: workflow costuma ser um fluxograma que não se lê em miniatura. -->
        <div
            v-if="imagemAberta"
            class="fixed inset-0 z-50 flex items-center justify-center bg-corp-black/80 p-4"
            title="Clique para fechar"
            @click="imagemAberta = null"
        >
            <img :src="imagemAberta.url" :alt="imagemAberta.nome" class="max-h-full max-w-full rounded bg-white object-contain" />
        </div>

        <ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
    </AuthenticatedLayout>
</template>
