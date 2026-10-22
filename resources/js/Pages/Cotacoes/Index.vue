<script setup>
/**
 * Cotação — a pessoa envia a lista do cliente e o sistema diz, item a item, o que temos
 * no estoque de revenda. Substitui o antigo Cotacao.exe (ver docs/cotacao.md).
 *
 * O estoque NÃO é enviado aqui: vem do relatório 026 do TOTVS, de hora em hora. A tela
 * só avisa quando o retrato está velho.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import Pagination from '@/Components/Pagination.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    cotacoes: { type: Object, required: true },
    emAndamento: { type: Boolean, default: false },
    estoque: { type: Object, required: true },
    mostraAutor: { type: Boolean, default: false },
    maxUploadMb: { type: Number, default: 10 },
});

const form = useForm({ arquivo: null, cliente_id: null });
const inputArquivo = ref(null);
const cliente = ref(null);
const busca = ref('');
const sugestoes = ref([]);
let atraso = null;

watch(busca, (termo) => {
    clearTimeout(atraso);
    if (cliente.value || termo.trim().length < 2) {
        sugestoes.value = [];
        return;
    }
    atraso = setTimeout(async () => {
        try {
            const r = await fetch(route('orcamentos.buscaClientes', { q: termo }), { headers: { Accept: 'application/json' } });
            // A busca do orçamento também traz leads; a cotação liga só a cliente da carteira.
            sugestoes.value = r.ok ? (await r.json()).filter((c) => c.origem === 'cliente') : [];
        } catch {
            sugestoes.value = [];
        }
    }, 250);
});

function escolherCliente(c) {
    cliente.value = c;
    form.cliente_id = c.clienteId;
    busca.value = '';
    sugestoes.value = [];
}

function limparCliente() {
    cliente.value = null;
    form.cliente_id = null;
}

function aoEscolherArquivo(e) {
    form.arquivo = e.target.files?.[0] ?? null;
}

function enviar() {
    form.post(route('cotacoes.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            cliente.value = null;
            if (inputArquivo.value) inputArquivo.value.value = '';
        },
    });
}

/* Recarrega sozinha enquanto há cotação em preparo — mesmo desenho de Meus downloads. */
let timer = null;
function ajustarPoll(ativo) {
    clearInterval(timer);
    timer = ativo ? setInterval(() => router.reload({ only: ['cotacoes', 'emAndamento'] }), 4000) : null;
}
watch(() => props.emAndamento, ajustarPoll, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));

const linhas = computed(() => props.cotacoes.data ?? []);

function estado(c) {
    if (c.travou) return { tone: 'danger', texto: 'Interrompida' };
    if (c.status === 'processando') return { tone: 'warn', texto: 'Comparando' };
    if (c.status === 'falha') return { tone: 'danger', texto: 'Falhou' };
    if (c.pendentes > 0) return { tone: 'warn', texto: `${c.pendentes} a conferir` };
    return { tone: 'ok', texto: 'Conferida' };
}

const reais = (v) => Number(v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
</script>

<template>
    <Head title="Cotação" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1800px] space-y-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero title="Cotação">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M6 3.6h9l3 3v13.8H6zM9 9.4h6M9 13h3M12.6 16.6l1.8 1.8 3.2-3.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    Envie a lista que o cliente mandou: o sistema compara cada item com o nosso estoque de revenda e
                    mostra o que precisa da sua conferência. O que você confere fica guardado para todo mundo.
                </template>
                <template #meta>
                    <StatusPill v-if="estoque.itens === 0" tone="danger" surface="dark">Estoque não carregado</StatusPill>
                    <StatusPill v-else :tone="estoque.desatualizado ? 'warn' : 'ok'" surface="dark">
                        Estoque de {{ estoque.em }}
                    </StatusPill>
                </template>
            </PageHero>

            <DarkCard title="Nova cotação" subtitle="Excel (.xlsx) ou CSV, do jeito que o cliente mandou">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>

                <form class="grid gap-4 p-4 md:grid-cols-[1fr_1fr_auto] md:items-end" @submit.prevent="enviar">
                    <div>
                        <label class="block text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Lista do cliente</label>
                        <input
                            ref="inputArquivo"
                            type="file"
                            accept=".xlsx,.csv"
                            class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded file:border-0 file:bg-navy file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
                            @change="aoEscolherArquivo"
                        />
                        <p class="mt-1 text-xs text-gray-400">Até {{ maxUploadMb }} MB. Não precisa ajustar colunas: o sistema acha a descrição sozinho.</p>
                        <InputError :message="form.errors.arquivo" class="mt-1" />
                    </div>

                    <div class="relative">
                        <label class="block text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Cliente (opcional)</label>
                        <div v-if="cliente" class="mt-1 flex items-center justify-between gap-2 rounded border border-gray-300 bg-gray-50 px-2 py-1.5 text-sm">
                            <span class="truncate">{{ cliente.nome }} <span class="text-xs text-gray-400">{{ cliente.cnpj }}</span></span>
                            <button type="button" class="text-xs font-semibold text-gray-500 hover:text-gray-800" @click="limparCliente">trocar</button>
                        </div>
                        <input
                            v-else
                            v-model="busca"
                            type="text"
                            placeholder="Razão social ou CNPJ da carteira"
                            class="mt-1 block w-full rounded border-gray-300 py-1.5 text-sm"
                        />
                        <ul v-if="sugestoes.length" class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded border border-gray-200 bg-white text-sm shadow">
                            <li v-for="c in sugestoes" :key="c.clienteId">
                                <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50" @click="escolherCliente(c)">
                                    {{ c.nome }} <span class="text-xs text-gray-400">{{ c.cnpj }}</span>
                                </button>
                            </li>
                        </ul>
                        <InputError :message="form.errors.cliente_id" class="mt-1" />
                    </div>

                    <button
                        type="submit"
                        :disabled="!form.arquivo || form.processing || estoque.itens === 0"
                        class="rounded bg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-navy/90 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ form.processing ? 'Enviando…' : 'Comparar com o estoque' }}
                    </button>
                </form>
            </DarkCard>

            <DarkCard title="Cotações" :subtitle="mostraAutor ? 'Todas, da mais recente para a mais antiga' : 'As suas, da mais recente para a mais antiga'">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <rect x="4" y="3" width="16" height="18" rx="2" />
                        <path d="M8 8h8M8 12h8M8 16h5" stroke-linecap="round" />
                    </svg>
                </template>

                <p v-if="linhas.length === 0" class="px-4 py-10 text-center text-sm text-gray-500">
                    Nenhuma cotação ainda. Envie a lista de um cliente acima.
                </p>

                <template v-else>
                    <div class="tbl-wrap">
                        <table class="tbl tbl-cartoes sm:min-w-[900px]">
                            <thead>
                                <tr class="tbl-head-row">
                                    <th class="tbl-th">Lista</th>
                                    <th v-if="mostraAutor" class="tbl-th">Quem enviou</th>
                                    <th class="tbl-th">Itens</th>
                                    <th class="tbl-th">Valor no período</th>
                                    <th class="tbl-th">Enviada em</th>
                                    <th class="tbl-th">Situação</th>
                                    <th class="tbl-th">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="tbl-body">
                                <tr v-for="c in linhas" :key="c.id" class="tbl-row">
                                    <td class="tbl-td tbl-td-titulo">
                                        <span class="tbl-main sm:max-w-[320px]">{{ c.arquivo }}</span>
                                        <span v-if="c.cliente" class="tbl-sub">{{ c.cliente }}</span>
                                    </td>
                                    <td v-if="mostraAutor" class="tbl-td" data-rotulo="Quem enviou">{{ c.autor }}</td>
                                    <td class="tbl-td" data-rotulo="Itens">{{ c.itens.toLocaleString('pt-BR') }}</td>
                                    <td class="tbl-td" data-rotulo="Valor">{{ reais(c.valor) }}</td>
                                    <td class="tbl-td" data-rotulo="Enviada em">{{ c.criadaEm }}</td>
                                    <td class="tbl-td" data-rotulo="Situação">
                                        <StatusPill size="sm" :tone="estado(c).tone" :title="c.erro || null">{{ estado(c).texto }}</StatusPill>
                                        <span v-if="c.status === 'falha' && c.erro" class="tbl-sub sm:mx-auto sm:max-w-[260px]">{{ c.erro }}</span>
                                    </td>
                                    <td class="tbl-td">
                                        <div class="tbl-acoes">
                                            <Link
                                                v-if="c.status === 'pronta' && c.pendentes > 0"
                                                :href="route('cotacoes.conferir', c.id)"
                                                class="tbl-acao tbl-acao-verde"
                                                title="Conferir"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4 10-10" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                            </Link>
                                            <Link
                                                v-if="c.status === 'pronta'"
                                                :href="route('cotacoes.show', c.id)"
                                                class="tbl-acao tbl-acao-neutro"
                                                title="Ver resultado"
                                            >
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 py-3">
                        <Pagination :meta="cotacoes" :only="['cotacoes', 'emAndamento']" />
                    </div>
                </template>
            </DarkCard>
        </div>
    </AuthenticatedLayout>
</template>
