<script setup>
/**
 * O quadro do funil de leads.
 *
 * ⚠️ NUNCA CARREGA A COLUNA INTEIRA. São ~17 mil leads e a esmagadora maioria está em
 * "Novo" (a base de prospecção importada nunca foi trabalhada). O servidor manda o TOTAL
 * de cada coluna mais os primeiros N cards; o resto vem por "carregar mais", com
 * paginação por CURSOR — offset alto faz o MySQL trocar de plano e varrer a tabela.
 *
 * ⚠️ Ganho e Perdido NÃO são colunas: viram contador no topo. Coluna de ganho cresce para
 * sempre e vira lixo visual — nos CRMs que funcionam, desfecho é ação, não coluna.
 *
 * ⚠️ ATUALIZAÇÃO OTIMISTA. Mover um card não recarrega o quadro: o card muda de coluna na
 * hora e o PATCH sai atrás; se falhar, o card volta para onde estava. Um reload por
 * movimento tornaria o kanban insuportável — é a diferença entre arrastar e esperar.
 */
import FunilCard from '@/Components/Leads/FunilCard.vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import { ETAPAS_ABERTAS, ROTULOS_ETAPA_LEAD } from '@/constants/leads.js';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    funil: { type: Object, required: true },
});

/** Cópia local: é ela que a atualização otimista mexe. */
const colunas = reactive(props.funil.colunas.map((c) => ({ ...c, cards: [...c.cards] })));
const fechados = reactive({ ...props.funil.fechados });

const arrastado = ref(null);
const colunaAlvo = ref(null);
const carregando = ref(null);
const erro = ref('');

const modalPerda = ref(false);
const cardPerdendo = ref(null);
const motivoPerda = ref('');

const totalEmJogo = computed(() => colunas.reduce((soma, c) => soma + c.total, 0));

function colunaDe(etapa) {
    return colunas.find((c) => c.etapa === etapa);
}

function removerCard(id) {
    for (const coluna of colunas) {
        const i = coluna.cards.findIndex((c) => c.id === id);
        if (i !== -1) {
            const [card] = coluna.cards.splice(i, 1);
            coluna.total -= 1;

            return { card, coluna };
        }
    }

    return null;
}

/**
 * O movimento, em um lugar só — arrastar, botão "→" e desfecho caem todos aqui, e todos
 * batem no mesmo endpoint (`leads.etapa`). Regra de ouro nº 8.
 */
function mover(card, etapa, motivo = null) {
    const origem = removerCard(card.id);
    if (!origem) return;

    const destino = colunaDe(etapa);
    if (destino) {
        const proxima = ETAPAS_ABERTAS[ETAPAS_ABERTAS.indexOf(etapa) + 1] ?? null;
        destino.cards.unshift({ ...card, etapa, proximaEtapa: proxima, paradoDesde: new Date().toISOString() });
        destino.total += 1;
    } else if (etapa in fechados) {
        // Desfecho: o card sai do quadro e só engrossa o contador.
        fechados[etapa] += 1;
    }

    erro.value = '';

    patchEtapa(card, etapa, motivo).catch(() => {
        // Desfaz: o servidor é a fonte da verdade, a tela era só um palpite.
        desfazer(card, origem, etapa);
        erro.value = 'Não foi possível mover este lead. A tela foi revertida.';
    });
}

/**
 * ⚠️ `fetch`, não `router.patch`. Mover um card não é navegação: o redirect do Inertia
 * refazia a visita, e como `funil` é prop OPCIONAL ela não voltava — o quadro sumia da
 * tela a cada movimento. Verificado no navegador.
 */
async function patchEtapa(card, etapa, motivo) {
    const res = await fetch(route('leads.etapa', card.id), {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenCsrf(),
        },
        body: JSON.stringify({ etapa, motivo_perda: motivo }),
    });

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
}

/** O Laravel aceita o valor do cookie XSRF-TOKEN (decodificado) neste header. */
function tokenCsrf() {
    const bruto = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return bruto ? decodeURIComponent(bruto.split('=')[1]) : '';
}

function desfazer(card, origem, etapaTentada) {
    const destino = colunaDe(etapaTentada);
    if (destino) {
        const i = destino.cards.findIndex((c) => c.id === card.id);
        if (i !== -1) {
            destino.cards.splice(i, 1);
            destino.total -= 1;
        }
    } else if (etapaTentada in fechados) {
        fechados[etapaTentada] -= 1;
    }

    origem.coluna.cards.unshift(card);
    origem.coluna.total += 1;
}

function avancar(card) {
    if (card.proximaEtapa) mover(card, card.proximaEtapa);
}

function ganhar(card) {
    mover(card, 'ganho');
}

/** Perder sem dizer por quê é o que torna o funil inútil como diagnóstico. */
function abrirPerda(card) {
    cardPerdendo.value = card;
    motivoPerda.value = '';
    modalPerda.value = true;
}

function confirmarPerda() {
    if (!motivoPerda.value.trim()) return;
    mover(cardPerdendo.value, 'perdido', motivoPerda.value.trim());
    modalPerda.value = false;
}

function soltarEm(etapa) {
    colunaAlvo.value = null;
    const card = arrastado.value;
    arrastado.value = null;

    if (!card || card.etapa === etapa) return;
    mover(card, etapa);
}

async function carregarMais(coluna) {
    if (carregando.value) return;
    carregando.value = coluna.etapa;

    try {
        const ultimo = coluna.cards[coluna.cards.length - 1];
        const url = route('leads.funil.mais', { etapa: coluna.etapa, depois: ultimo?.id ?? '' });
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const dados = await res.json();
        coluna.cards.push(...dados.cards);
    } catch {
        erro.value = 'Não foi possível carregar mais cards desta coluna.';
    } finally {
        carregando.value = null;
    }
}
</script>

<template>
    <div>
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <span class="font-semibold uppercase tracking-wide text-gray-400">Em jogo</span>
            <span class="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 font-semibold text-gray-600">
                {{ totalEmJogo }}
            </span>
            <span class="rounded border border-green-300 bg-green-50 px-2 py-0.5 font-semibold text-green-700">
                Ganhos {{ fechados.ganho }}
            </span>
            <span class="rounded border border-red-300 bg-red-50 px-2 py-0.5 font-semibold text-red-700">
                Perdidos {{ fechados.perdido }}
            </span>
        </div>

        <p v-if="erro" class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
            {{ erro }}
        </p>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="coluna in colunas"
                :key="coluna.etapa"
                class="flex flex-col rounded border bg-zinc-50 transition"
                :class="colunaAlvo === coluna.etapa ? 'border-cyan bg-cyan/5' : 'border-gray-200'"
                @dragover.prevent="colunaAlvo = coluna.etapa"
                @dragleave="colunaAlvo === coluna.etapa && (colunaAlvo = null)"
                @drop.prevent="soltarEm(coluna.etapa)"
            >
                <div class="flex items-center justify-between border-b border-gray-200 px-2.5 py-2">
                    <span class="text-[0.7rem] font-semibold uppercase tracking-wide text-gray-600">
                        {{ ROTULOS_ETAPA_LEAD[coluna.etapa] }}
                    </span>
                    <span class="rounded bg-gray-200 px-1.5 text-[0.65rem] font-semibold text-gray-600">
                        {{ coluna.total }}
                    </span>
                </div>

                <div class="flex max-h-[32rem] flex-col gap-2 overflow-y-auto p-2">
                    <FunilCard
                        v-for="card in coluna.cards"
                        :key="card.id"
                        :card="card"
                        :arrastando="arrastado?.id === card.id"
                        @avancar="avancar"
                        @ganhar="ganhar"
                        @perder="abrirPerda"
                        @arrastar-inicio="arrastado = $event"
                        @arrastar-fim="arrastado = null"
                    />

                    <p v-if="!coluna.cards.length" class="px-1 py-4 text-center text-[0.7rem] text-gray-400">
                        Nenhum lead nesta etapa.
                    </p>

                    <button
                        v-if="coluna.cards.length < coluna.total"
                        type="button"
                        class="rounded border border-dashed border-gray-300 py-1.5 text-[0.7rem] font-medium text-gray-500 hover:border-cyan hover:text-cyan"
                        :disabled="carregando === coluna.etapa"
                        @click="carregarMais(coluna)"
                    >
                        {{ carregando === coluna.etapa ? 'Carregando…' : `Carregar mais (${coluna.total - coluna.cards.length} restantes)` }}
                    </button>
                </div>
            </div>
        </div>

        <ModalPadrao
            :show="modalPerda"
            titulo="Marcar lead como perdido"
            :subtitulo="cardPerdendo?.razaoSocial || ''"
            max-width="md"
            @close="modalPerda = false"
        >
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500">
                Motivo da perda
            </label>
            <textarea
                v-model="motivoPerda"
                rows="3"
                maxlength="255"
                class="mt-1 w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                placeholder="Preço, prazo, fechou com concorrente…"
            ></textarea>
            <p class="mt-1 text-[0.7rem] text-gray-400">
                Sem o motivo, o funil não responde onde a empresa está perdendo negócio.
            </p>

            <template #footer>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="modalPerda = false">
                    Cancelar
                </button>
                <button
                    type="button"
                    class="rounded bg-corp-black px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-40"
                    :disabled="!motivoPerda.trim()"
                    @click="confirmarPerda"
                >
                    Marcar como perdido
                </button>
            </template>
        </ModalPadrao>
    </div>
</template>
