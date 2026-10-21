<script setup>
/**
 * Histórico de notificações — lidas e não lidas.
 *
 * ⚠️ POR QUE ESTA TELA EXISTE: o sino só mostra as não lidas. Clicada, a notificação sumia
 * sem caminho de volta — "quem aprovou aquele orçamento?", "qual era o lead do site de
 * terça?" não tinham onde ser respondidas. Mesmo buraco que a central de downloads fechou
 * para as planilhas.
 *
 * Lista SÓ as próprias, para todos os perfis (ver NotificacaoController::historico).
 */
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import FilterField from '@/Components/FilterField.vue';
import Pagination from '@/Components/Pagination.vue';
import { abrirLinkDaNotificacao, formatarDataHora } from '@/utils/notificacao';

const props = defineProps({
    notificacoes: { type: Object, required: true },
    filtros: { type: Object, required: true },
    naoLidas: { type: Number, default: 0 },
    diasGuarda: { type: Number, default: 30 },
});

/*
 * Cópia local das linhas: marcar como lida atualiza a tela na hora, sem refazer a visita
 * (mesmo comportamento otimista do sino). Volta a acompanhar as props quando a página ou
 * o filtro mudam.
 */
const linhas = ref([...(props.notificacoes.data ?? [])]);
const contagemNaoLidas = ref(props.naoLidas);

watch(() => props.notificacoes, (n) => { linhas.value = [...(n.data ?? [])]; });
watch(() => props.naoLidas, (n) => { contagemNaoLidas.value = n; });

const situacao = ref(props.filtros.situacao ?? '');

watch(situacao, (valor) => {
    router.get(route('notificacoes.historico'), valor ? { situacao: valor } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
});

const temLinhas = computed(() => linhas.value.length > 0);

function marcarLocalmente(n) {
    if (n.lidaEm) return;
    n.lidaEm = new Date().toISOString();
    contagemNaoLidas.value = Math.max(0, contagemNaoLidas.value - 1);
}

function marcarLida(n) {
    if (n.lidaEm) return;
    marcarLocalmente(n);
    axios.post(route('notificacoes.marcarLida', n.id));
}

function abrir(n) {
    if (!n.lidaEm) {
        marcarLocalmente(n);
        axios.post(route('notificacoes.marcarLida', n.id));
    }
    abrirLinkDaNotificacao(n);
}

async function marcarTodas() {
    linhas.value.forEach((n) => { if (!n.lidaEm) n.lidaEm = new Date().toISOString(); });
    contagemNaoLidas.value = 0;
    await axios.post(route('notificacoes.marcarTodas'));
    // Com o filtro "não lidas" a lista ficou desatualizada: recarrega.
    if (situacao.value === 'nao_lidas') router.reload({ only: ['notificacoes', 'naoLidas'] });
}

const vazio = computed(() => {
    if (situacao.value === 'nao_lidas') return 'Nenhuma notificação não lida.';
    if (situacao.value === 'lidas') return 'Nenhuma notificação lida nos últimos dias.';
    return 'Você ainda não recebeu nenhuma notificação.';
});
</script>

<template>
    <Head title="Notificações" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Notificações">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m0 0v1a3 3 0 1 0 6 0v-1" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    Tudo o que chegou no sino, inclusive o que você já leu. As lidas ficam aqui por
                    {{ diasGuarda }} dias; as não lidas ficam até você abri-las.
                </template>
                <template #meta>
                    <StatusPill :tone="contagemNaoLidas > 0 ? 'warn' : 'neutral'" surface="dark">
                        {{ contagemNaoLidas }} não lida{{ contagemNaoLidas === 1 ? '' : 's' }}
                    </StatusPill>
                </template>
                <template #filtros>
                    <FilterField v-model="situacao" label="Situação">
                        <option value="">Todas</option>
                        <option value="nao_lidas">Não lidas</option>
                        <option value="lidas">Lidas</option>
                    </FilterField>
                </template>
            </PageHero>

            <DarkCard title="Histórico" subtitle="Da mais recente para a mais antiga">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M12 7v5l3 2M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template v-if="contagemNaoLidas > 0" #actions>
                    <button
                        type="button"
                        class="rounded border border-white/30 px-2.5 py-1 text-xs font-medium text-white hover:bg-white/10"
                        @click="marcarTodas"
                    >
                        Marcar todas como lidas
                    </button>
                </template>

                <p v-if="!temLinhas" class="px-4 py-10 text-center text-sm text-gray-500">{{ vazio }}</p>

                <template v-else>
                    <!-- Lista e não tabela: cada notificação é uma frase (título + mensagem),
                         mesma leitura do sino. Colunas só espalhariam o texto. -->
                    <ul class="divide-y divide-gray-200">
                        <li
                            v-for="n in linhas"
                            :key="n.id"
                            class="flex items-start gap-3 px-3 py-2.5"
                            :class="[
                                n.destaque ? 'border-l-4 border-intranet bg-intranet/10' : '',
                                n.link ? 'cursor-pointer hover:bg-gray-50' : '',
                            ]"
                            @click="n.link && abrir(n)"
                        >
                            <span
                                class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                :class="n.lidaEm ? 'bg-gray-200' : (n.destaque ? 'bg-intranet-dark' : 'bg-cyan')"
                                :title="n.lidaEm ? 'Lida' : 'Não lida'"
                            />
                            <div class="min-w-0 flex-1">
                                <span
                                    v-if="n.destaque"
                                    class="mb-0.5 inline-block rounded bg-intranet-dark px-1.5 py-px text-[0.58rem] font-bold uppercase tracking-wide text-white"
                                >Importante</span>
                                <p
                                    class="text-sm"
                                    :class="[
                                        n.lidaEm ? 'font-medium text-gray-600' : 'font-semibold text-gray-800',
                                        n.destaque ? 'text-intranet-dark' : '',
                                    ]"
                                >
                                    {{ n.titulo }}
                                </p>
                                <p v-if="n.mensagem" class="text-xs text-gray-500">{{ n.mensagem }}</p>
                                <p class="mt-0.5 text-[0.65rem] text-gray-400">
                                    {{ formatarDataHora(n.criadoEm) }}
                                    <template v-if="n.lidaEm"> · lida {{ formatarDataHora(n.lidaEm) }}</template>
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1" @click.stop>
                                <button
                                    v-if="!n.lidaEm"
                                    type="button"
                                    class="tbl-acao tbl-acao-neutro"
                                    title="Marcar como lida"
                                    @click="marcarLida(n)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <path d="m5 12.5 4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    v-if="n.link"
                                    type="button"
                                    class="tbl-acao"
                                    :class="n.download ? 'tbl-acao-verde' : 'tbl-acao-teal'"
                                    :title="n.download ? 'Baixar arquivo' : 'Abrir'"
                                    @click="abrir(n)"
                                >
                                    <svg v-if="n.download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                        <path d="M14 5h5v5M19 5l-8 8M10 6H6a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </li>
                    </ul>

                    <div class="px-3 pb-3">
                        <Pagination :meta="notificacoes" :only="['notificacoes', 'naoLidas']" />
                    </div>
                </template>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
