<script setup>
/**
 * Quem atende cada segmento: uma linha por segmento, com os nomes das equipes internas.
 * Quem está na tela e quem entra na conta vem do servidor (`QuadroSegmentosResolver`).
 *
 * Tirar é o × no nome; acrescentar é o seletor da coluna Ações. As duas gravam na hora.
 *
 * ⭐ A ESTRELA no nome marca o especialista do segmento (admin + diretor). É o único lugar
 * do sistema onde ele é definido. Um por segmento: marcar outra pessoa tira a anterior, e
 * clicar na estrela acesa desmarca.
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

const linhas = computed(() => {
    const termo = busca.value.trim().toLowerCase();

    return props.quadro.segmentos
        .map((segmento) => ({
            ...segmento,
            pessoas: props.quadro.pessoas.filter((p) => p.segmentosIds.includes(segmento.id)),
        }))
        .filter((s) => !termo
            || s.nome.toLowerCase().includes(termo)
            || s.pessoas.some((p) => p.nome.toLowerCase().includes(termo)))
        .sort((a, b) => b.pessoas.length - a.pessoas.length || a.nome.localeCompare(b.nome));
});

const semSegmento = computed(() => props.quadro.pessoas.filter((p) => p.segmentosIds.length === 0));

function candidatos(segmento) {
    return props.quadro.pessoas.filter((p) => !p.segmentosIds.includes(segmento.id));
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

function adicionar(segmento, evento) {
    const pessoa = props.quadro.pessoas.find((p) => p.id === Number(evento.target.value));
    evento.target.value = '';

    if (pessoa) {
        salvar(pessoa, [...pessoa.segmentosIds, segmento.id]);
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
                placeholder="Segmento ou nome..."
                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
            />
        </div>

        <p v-if="semSegmento.length" class="text-xs text-gray-600">
            <span class="font-semibold text-amber-dark">Sem segmento:</span>
            {{ semSegmento.map((p) => p.nome).join(' · ') }}
        </p>

        <div class="overflow-hidden rounded border border-gray-300 bg-white">
            <div class="tbl-wrap">
                <table class="tbl tbl-cartoes sm:min-w-[860px]">
                    <thead>
                        <tr class="tbl-head-row">
                            <th class="tbl-th">Segmento</th>
                            <th class="tbl-th">Nome</th>
                            <th class="tbl-th">Pessoas</th>
                            <th v-if="podeEditar" class="tbl-th">Adicionar</th>
                        </tr>
                    </thead>
                    <tbody class="tbl-body">
                        <tr v-for="segmento in linhas" :key="segmento.id" class="tbl-row">
                            <td class="tbl-td tbl-td-titulo"><span class="tbl-main">{{ segmento.nome }}</span></td>
                            <td class="tbl-td" data-rotulo="Nome">
                                <div v-if="segmento.pessoas.length" class="flex flex-wrap items-center justify-center gap-1">
                                    <span
                                        v-for="pessoa in segmento.pessoas"
                                        :key="pessoa.id"
                                        class="inline-flex items-center gap-1 rounded-full border border-gray-300 bg-gray-50 px-2 py-0.5 text-[0.65rem] font-medium uppercase tracking-wide text-gray-700"
                                    >
                                        <button
                                            v-if="ehEspecialista(pessoa, segmento) || podeDefinirEspecialista"
                                            type="button"
                                            class="leading-none"
                                            :class="[
                                                ehEspecialista(pessoa, segmento) ? 'text-amber-dark' : 'text-gray-400 hover:text-amber-dark',
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
                                        {{ pessoa.nome }}
                                        <button
                                            v-if="podeEditar"
                                            type="button"
                                            class="leading-none text-gray-400 hover:text-red-600"
                                            :title="`Tirar ${pessoa.nome} de ${segmento.nome}`"
                                            :disabled="salvando"
                                            @click="tirar(pessoa, segmento)"
                                        >
                                            ×
                                        </button>
                                    </span>
                                </div>
                                <span v-else class="text-xs text-gray-400">—</span>
                            </td>
                            <td class="tbl-td tabular-nums" data-rotulo="Pessoas">{{ segmento.pessoas.length }}</td>
                            <td v-if="podeEditar" class="tbl-td" data-rotulo="Adicionar">
                                <select
                                    class="w-full rounded border-gray-300 py-1 text-xs text-gray-600 focus:border-cyan focus:ring-cyan"
                                    :disabled="salvando || !candidatos(segmento).length"
                                    :aria-label="`Adicionar pessoa em ${segmento.nome}`"
                                    @change="adicionar(segmento, $event)"
                                >
                                    <option value="">Adicionar...</option>
                                    <option v-for="pessoa in candidatos(segmento)" :key="pessoa.id" :value="pessoa.id">
                                        {{ pessoa.nome }}
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr v-if="!linhas.length">
                            <td :colspan="podeEditar ? 4 : 3" class="tbl-td text-gray-400">Nada encontrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
