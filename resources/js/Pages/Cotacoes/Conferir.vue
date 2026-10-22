<script setup>
/**
 * Conferência: um item da lista por vez, o de maior valor primeiro. A pessoa escolhe o
 * nosso produto que corresponde, busca outro, marca "não trabalhamos" ou pula.
 *
 * ⚠️ `postJson` + recarga parcial, e não `router.post`: o redirect do Inertia refaria a
 * visita inteira a cada clique. Aqui só voltam `item`, `restantes` e `feitos` — é o que
 * mantém cada decisão dentro do orçamento de escrita (Regra de ouro nº 9).
 *
 * Cada decisão vale para todo mundo dali em diante: na próxima lista que tiver o mesmo
 * texto, o item já sai "Já validado antes".
 */
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHero from '@/Components/PageHero.vue';
import DarkCard from '@/Components/DarkCard.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { postJson } from '@/utils/csrf';

const props = defineProps({
    cotacao: { type: Object, required: true },
    item: { type: Object, default: null },
    restantes: { type: Number, default: 0 },
    feitos: { type: Number, default: 0 },
    revisaveis: { type: Number, default: 0 },
    comCusto: { type: Boolean, default: false },
});

const enviando = ref(false);
const erro = ref('');
const busca = ref('');
const achados = ref([]);
let atraso = null;

const reais = (v) => Number(v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const numero = (v) => (v == null ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 }));
const progresso = computed(() => (props.revisaveis > 0 ? Math.round((props.feitos / props.revisaveis) * 100) : 100));

// Item novo na tela: limpa a busca e o erro do anterior.
watch(() => props.item?.id, () => {
    busca.value = '';
    achados.value = [];
    erro.value = '';
});

watch(busca, (termo) => {
    clearTimeout(atraso);
    if (termo.trim().length < 2) {
        achados.value = [];
        return;
    }
    atraso = setTimeout(async () => {
        try {
            const r = await fetch(route('cotacoes.produtos', { q: termo }), { headers: { Accept: 'application/json' } });
            achados.value = r.ok ? await r.json() : [];
        } catch {
            achados.value = [];
        }
    }, 250);
});

async function decidir(acao, codigo = null) {
    if (enviando.value || !props.item) return;
    enviando.value = true;
    erro.value = '';

    try {
        const r = await postJson(route('cotacoes.itens.decidir', props.item.id), { acao, codigo });
        if (!r.ok) {
            const corpo = await r.json().catch(() => ({}));
            erro.value = corpo.mensagem || 'Não foi possível gravar. Tente de novo.';
            return;
        }
        router.reload({ only: ['item', 'restantes', 'feitos'] });
    } catch {
        erro.value = 'Sem conexão com o servidor. Tente de novo.';
    } finally {
        enviando.value = false;
    }
}
</script>

<template>
    <Head :title="`Conferir — ${cotacao.arquivo}`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-[1200px] space-y-4 px-3 py-4 sm:px-4 lg:px-6">
            <PageHero :title="`Conferir: ${cotacao.arquivo}`">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="m5 12 4 4 10-10" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <template #subtitle>
                    {{ feitos }} de {{ revisaveis }} conferidos · faltam {{ restantes }}. O que você decidir fica guardado,
                    pode parar quando quiser.
                </template>
                <template #meta>
                    <Link :href="route('cotacoes.show', cotacao.id)" class="text-xs font-semibold text-gray-300 hover:text-white">Ver resultado →</Link>
                </template>
            </PageHero>

            <div class="h-1.5 w-full overflow-hidden rounded bg-gray-200">
                <div class="h-full bg-teal transition-all" :style="{ width: `${progresso}%` }" />
            </div>

            <DarkCard v-if="!item" title="Conferência terminada" subtitle="Nada mais esperando por você nesta lista">
                <template #icon>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <path d="m5 12 4 4 10-10" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </template>
                <div class="flex flex-col items-center gap-3 px-4 py-10 text-center">
                    <p class="text-sm text-gray-600">Todos os itens que precisavam de conferência foram resolvidos (ou pulados).</p>
                    <Link :href="route('cotacoes.show', cotacao.id)" class="rounded bg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-navy/90">
                        Ver resultado e baixar a planilha
                    </Link>
                </div>
            </DarkCard>

            <template v-else>
                <DarkCard title="O cliente pediu" :subtitle="`${numero(item.qtd)} ${item.unidade ?? ''} · ${reais(item.valor)} no período`">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                            <path d="M6 3.6h9l3 3v13.8H6zM9 9.4h6M9 13h6M9 16.6h3" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>
                    <template #actions>
                        <StatusPill :tone="item.statusTom" surface="dark">{{ item.statusRotulo }}</StatusPill>
                    </template>

                    <div class="space-y-3 p-4">
                        <p class="text-lg font-semibold leading-snug text-gray-800">{{ item.descricao }}</p>
                        <p class="text-xs text-gray-400">
                            <template v-if="item.codCliente">Cód. do cliente {{ item.codCliente }} · </template>
                            <template v-if="item.subgrupo">{{ item.subgrupo }} · </template>
                            <template v-if="item.marca">Marca pedida: {{ item.marca }} · </template>
                            {{ item.ordens }} ordem(ns) no período
                        </p>
                        <ul class="space-y-1 rounded border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                            <li v-for="(m, i) in item.motivos" :key="i">· {{ m }}</li>
                        </ul>
                    </div>
                </DarkCard>

                <DarkCard title="Qual é o nosso produto?" subtitle="Os mais parecidos que o sistema encontrou no estoque de revenda">
                    <template #icon>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                            <path d="M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8" stroke-linejoin="round" />
                        </svg>
                    </template>

                    <div class="space-y-3 p-4">
                        <p v-if="erro" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">{{ erro }}</p>

                        <p v-if="item.candidatos.length === 0" class="text-sm text-gray-500">
                            Nenhum candidato parecido. Busque abaixo ou marque que não trabalhamos com esse.
                        </p>

                        <button
                            v-for="c in item.candidatos"
                            :key="c.codigo"
                            type="button"
                            :disabled="enviando || !c.noEstoque"
                            class="flex w-full flex-col gap-1 rounded border px-3 py-2 text-left transition hover:border-teal hover:bg-teal/5 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-row sm:items-center sm:justify-between"
                            :class="c.codigo === item.sugerido ? 'border-teal bg-teal/5' : 'border-gray-200'"
                            @click="decidir('escolher', c.codigo)"
                        >
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-800">{{ c.descricao ?? 'Saiu do estoque de revenda' }}</span>
                                <span class="block text-xs text-gray-400">
                                    {{ c.codigo }}<template v-if="c.marca"> · {{ c.marca }}</template>
                                    <template v-if="c.codigo === item.sugerido"> · sugestão do sistema</template>
                                </span>
                            </span>
                            <span class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                                <span :class="c.disponivel > 0 ? 'text-green-700' : 'text-gray-400'">
                                    {{ c.disponivel > 0 ? `${numero(c.disponivel)} disponíveis` : 'sem estoque' }}
                                </span>
                                <span v-if="comCusto && c.custo != null" class="text-gray-500">custo {{ reais(c.custo) }}</span>
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-600">{{ c.score }} pts</span>
                            </span>
                        </button>

                        <div class="border-t border-gray-100 pt-3">
                            <label class="block text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Não está aqui? Buscar no estoque</label>
                            <input
                                v-model="busca"
                                type="text"
                                placeholder="Código ou palavras da descrição"
                                class="mt-1 block w-full rounded border-gray-300 py-1.5 text-sm"
                            />
                            <ul v-if="achados.length" class="mt-2 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded border border-gray-200">
                                <li v-for="p in achados" :key="p.codigo">
                                    <button
                                        type="button"
                                        :disabled="enviando"
                                        class="flex w-full items-center justify-between gap-3 px-3 py-1.5 text-left text-sm hover:bg-gray-50"
                                        @click="decidir('escolher', p.codigo)"
                                    >
                                        <span class="min-w-0 truncate">{{ p.descricao }} <span class="text-xs text-gray-400">{{ p.codigo }}</span></span>
                                        <span class="shrink-0 text-xs text-gray-500">
                                            {{ numero(p.disponivel) }} disp.<template v-if="comCusto && p.custo != null"> · {{ reais(p.custo) }}</template>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="flex flex-col gap-2 border-t border-gray-100 pt-3 sm:flex-row sm:justify-between">
                            <button
                                type="button"
                                :disabled="enviando"
                                class="rounded border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50"
                                @click="decidir('nao_temos')"
                            >
                                Não trabalhamos com esse
                            </button>
                            <button
                                type="button"
                                :disabled="enviando"
                                class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                title="Sai da fila desta lista sem guardar nada"
                                @click="decidir('pular')"
                            >
                                Pular por agora
                            </button>
                        </div>
                    </div>
                </DarkCard>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
