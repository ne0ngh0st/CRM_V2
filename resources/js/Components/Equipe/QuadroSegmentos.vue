<script setup>
/**
 * "Divisão Segmentos Mercado": SUPERVISOR → VENDEDOR → SEGMENTOS, no formato da planilha
 * da diretoria (2026-11-03). Uma linha por vendedor, agrupada pela equipe; o supervisor é
 * a célula mesclada da esquerda, não uma linha.
 * Quem está na tela e quem entra na conta vem do servidor (`QuadroSegmentosResolver`).
 *
 * Tirar é o × no segmento; acrescentar é o seletor da coluna Adicionar. As duas gravam na hora.
 *
 * ⭐ A ESTRELA ao lado do segmento marca aquela pessoa como especialista dele (admin +
 * diretor). É o único lugar do sistema onde ele é definido. Um por segmento: marcar outra
 * pessoa tira a anterior, e clicar na estrela acesa desmarca.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import KpiTile from '@/Components/KpiTile.vue';

const props = defineProps({
    quadro: { type: Object, required: true },
    podeEditar: { type: Boolean, default: false },
    podeDefinirEspecialista: { type: Boolean, default: false },
});

const busca = ref('');
const salvando = ref(false);

const segmentosPorId = computed(() => new Map(props.quadro.segmentos.map((s) => [s.id, s])));

function segmentosDe(pessoa) {
    return pessoa.segmentosIds
        .map((id) => segmentosPorId.value.get(id))
        .filter(Boolean)
        .sort((a, b) => a.nome.localeCompare(b.nome));
}

/** Uma entrada por equipe que tem alguém para mostrar, na ordem que o servidor mandou. */
const equipes = computed(() => {
    const termo = busca.value.trim().toLowerCase();

    return props.quadro.equipes
        .map((equipe) => ({
            ...equipe,
            pessoas: props.quadro.pessoas
                .filter((p) => p.equipe === equipe.codigo)
                .map((p) => ({ ...p, segmentos: segmentosDe(p) }))
                .filter((p) => !termo
                    || p.nome.toLowerCase().includes(termo)
                    || equipe.nome.toLowerCase().includes(termo)
                    || p.segmentos.some((s) => s.nome.toLowerCase().includes(termo))),
        }))
        .filter((equipe) => equipe.pessoas.length);
});

function candidatos(pessoa) {
    return props.quadro.segmentos.filter((s) => !pessoa.segmentosIds.includes(s.id));
}

function salvar(pessoa, ids) {
    salvando.value = true;
    router.patch(route('equipe.atualizarSegmentos', pessoa.id), { segmentos: ids }, {
        preserveScroll: true,
        preserveState: true,
        only: ['quadro'],
        onFinish: () => {
            salvando.value = false;
        },
    });
}

function adicionar(pessoa, evento) {
    const id = Number(evento.target.value);
    evento.target.value = '';

    if (id) {
        salvar(pessoa, [...pessoa.segmentosIds, id]);
    }
}

function tirar(pessoa, segmento) {
    salvar(pessoa, pessoa.segmentosIds.filter((id) => id !== segmento.id));
}

function ehEspecialista(pessoa, segmento) {
    return segmento.especialista?.id === pessoa.id;
}

function alternarEspecialista(pessoa, segmento) {
    if (!props.podeDefinirEspecialista) {
        return;
    }
    salvando.value = true;
    router.patch(route('equipe.segmentos.especialista', segmento.id), {
        especialista_user_id: ehEspecialista(pessoa, segmento) ? null : pessoa.id,
    }, {
        preserveScroll: true,
        preserveState: true,
        only: ['quadro'],
        onFinish: () => {
            salvando.value = false;
        },
    });
}

function tituloEstrela(pessoa, segmento) {
    if (!ehEspecialista(pessoa, segmento)) {
        return `Marcar ${pessoa.nome} como especialista de ${segmento.nome}`;
    }

    return props.podeDefinirEspecialista
        ? `Especialista de ${segmento.nome} — clique para desmarcar`
        : `Especialista de ${segmento.nome}`;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap gap-2">
            <KpiTile :value="quadro.totais.pessoas" label="Na equipe" />
            <KpiTile :value="quadro.totais.comSegmento" label="Com segmento" tone="ok" />
            <KpiTile
                :value="quadro.totais.semSegmento"
                label="Sem segmento"
                :tone="quadro.totais.semSegmento ? 'warn' : 'ok'"
            />
            <KpiTile :value="quadro.totais.segmentosComGente" label="Segmentos cobertos" tone="info" />
        </div>

        <div class="flex flex-col gap-1 sm:max-w-xs">
            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
            <input
                v-model="busca"
                type="text"
                placeholder="Vendedor, supervisor ou segmento..."
                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
            />
        </div>

        <div class="overflow-hidden rounded border border-gray-300 bg-white">
            <div class="bg-corp-black px-4 py-2 text-center text-sm font-semibold uppercase tracking-wide text-white">
                Divisão Segmentos Mercado
            </div>
            <div class="tbl-wrap">
                <table class="tbl tbl-cartoes sm:min-w-[760px]">
                    <thead>
                        <tr class="tbl-head-row">
                            <th class="tbl-th sm:w-[18%]">Supervisor</th>
                            <th class="tbl-th sm:w-[30%]">Vendedor</th>
                            <th class="tbl-th">Segmentos</th>
                            <th v-if="podeEditar" class="tbl-th sm:w-[20%]">Adicionar</th>
                        </tr>
                    </thead>
                    <tbody class="tbl-body">
                        <template v-for="equipe in equipes" :key="equipe.codigo">
                            <tr v-for="(pessoa, i) in equipe.pessoas" :key="pessoa.id" class="tbl-row">
                                <!--
                                    Célula mesclada do supervisor. `border-r` à mão: nas linhas
                                    seguintes o vendedor é o PRIMEIRO filho do <tr> e o `divide-x`
                                    do `.tbl-row` não desenha a régua entre os dois. No celular ela
                                    some (o rowspan só existiria no primeiro cartão) e o nome desce
                                    para baixo do vendedor.
                                -->
                                <td
                                    v-if="i === 0"
                                    :rowspan="equipe.pessoas.length"
                                    class="tbl-td tbl-td-oculto border-r border-gray-200 bg-gray-50 text-sm font-bold uppercase tracking-wide text-gray-800"
                                >
                                    {{ equipe.nome }}
                                </td>
                                <td class="tbl-td tbl-td-titulo">
                                    <span class="tbl-main uppercase">{{ pessoa.nome }}</span>
                                    <span class="tbl-sub sm:hidden">Supervisor: {{ equipe.nome }}</span>
                                </td>
                                <td class="tbl-td sm:!p-0" data-rotulo="Segmentos">
                                    <ul v-if="pessoa.segmentos.length" class="divide-y divide-gray-200">
                                        <li
                                            v-for="segmento in pessoa.segmentos"
                                            :key="segmento.id"
                                            class="flex items-center justify-start gap-1.5 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-700 sm:justify-center sm:px-3"
                                        >
                                            <button
                                                v-if="ehEspecialista(pessoa, segmento) || podeDefinirEspecialista"
                                                type="button"
                                                class="leading-none"
                                                :class="[
                                                    ehEspecialista(pessoa, segmento) ? 'text-amber-dark' : 'text-gray-300 hover:text-amber-dark',
                                                    podeDefinirEspecialista ? 'cursor-pointer' : 'cursor-default',
                                                ]"
                                                :title="tituloEstrela(pessoa, segmento)"
                                                :aria-pressed="ehEspecialista(pessoa, segmento)"
                                                :disabled="salvando"
                                                @click="alternarEspecialista(pessoa, segmento)"
                                            >
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="h-3 w-3"
                                                    aria-hidden="true"
                                                    :fill="ehEspecialista(pessoa, segmento) ? 'currentColor' : 'none'"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linejoin="round"
                                                >
                                                    <path d="M12 3.2l2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.3 2.8 1-6-4.4-4.2 6-.9Z" />
                                                </svg>
                                            </button>
                                            {{ segmento.nome }}
                                            <button
                                                v-if="podeEditar"
                                                type="button"
                                                class="px-1 text-sm leading-none text-gray-400 hover:text-red-600"
                                                :title="`Tirar ${segmento.nome} de ${pessoa.nome}`"
                                                :disabled="salvando"
                                                @click="tirar(pessoa, segmento)"
                                            >
                                                ×
                                            </button>
                                        </li>
                                    </ul>
                                    <span v-else class="block py-1.5 text-xs font-medium text-amber-dark sm:px-3">Sem segmento</span>
                                </td>
                                <td v-if="podeEditar" class="tbl-td" data-rotulo="Adicionar">
                                    <select
                                        class="w-full rounded border-gray-300 py-1 text-xs text-gray-600 focus:border-cyan focus:ring-cyan"
                                        :disabled="salvando || !candidatos(pessoa).length"
                                        :aria-label="`Adicionar segmento para ${pessoa.nome}`"
                                        @change="adicionar(pessoa, $event)"
                                    >
                                        <option value="">Adicionar...</option>
                                        <option v-for="segmento in candidatos(pessoa)" :key="segmento.id" :value="segmento.id">
                                            {{ segmento.nome }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!equipes.length">
                            <td :colspan="podeEditar ? 4 : 3" class="tbl-td text-gray-400">Nada encontrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
