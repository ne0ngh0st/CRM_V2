<script setup>
/**
 * Mapa visual de cobertura: quem atende cada segmento.
 *
 * Três camadas, nenhuma delas é análise — só "quem é de onde":
 *   1. faixa mosaico (tamanho = quantidade de pessoas)
 *   2. faixa âmbar de quem ainda não tem segmento
 *   3. ilhas coloridas, uma por segmento com gente; vazios viram alvos de soltar
 *
 * Arrastar adiciona o segmento (a pessoa pode ter 1-2). Tirar é o X no canto
 * do retrato, ou o modal. Representante não arrasta nem some do SUPERMERCADISTA.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import KpiTile from '@/Components/KpiTile.vue';
import PessoaSegmentoChip from '@/Components/Equipe/PessoaSegmentoChip.vue';
import EditarSegmentosModal from '@/Components/Equipe/EditarSegmentosModal.vue';
import { corDoSegmento, CODIGO_SUPERMERCADISTA } from '@/constants/segmentos.js';

const props = defineProps({
    quadro: { type: Object, required: true },
    podeEditar: { type: Boolean, default: false },
});

const busca = ref('');
const arrastado = ref(null);
const alvo = ref(null);
const pessoaAtivaId = ref(null);
const salvando = ref(false);

const termo = computed(() => busca.value.trim().toLowerCase());

function casa(pessoa) {
    if (!termo.value) {
        return true;
    }

    return pessoa.nome.toLowerCase().includes(termo.value)
        || String(pessoa.codVendedor).toLowerCase().includes(termo.value);
}

function ehRepresentante(pessoa) {
    return pessoa.perfil === 'representante';
}

function pessoasDo(segmentoId) {
    return props.quadro.pessoas.filter(
        (p) => p.segmentosIds.includes(segmentoId) && casa(p),
    );
}

const semSegmento = computed(() =>
    props.quadro.pessoas.filter((p) => p.segmentosIds.length === 0 && casa(p)),
);

const preenchidos = computed(() => props.quadro.segmentos
    .map((s) => ({ ...s, pessoas: pessoasDo(s.id) }))
    .filter((s) => s.pessoas.length > 0)
    .sort((a, b) => b.pessoas.length - a.pessoas.length || a.nome.localeCompare(b.nome)));

const vazios = computed(() => {
    const ocupados = new Set(
        props.quadro.pessoas.flatMap((p) => p.segmentosIds),
    );

    return props.quadro.segmentos.filter((s) => !ocupados.has(s.id));
});

const mosaico = computed(() => {
    const faixas = props.quadro.segmentos
        .map((s) => ({
            ...s,
            quantidade: props.quadro.pessoas.filter((p) => p.segmentosIds.includes(s.id)).length,
        }))
        .filter((s) => s.quantidade > 0)
        .sort((a, b) => b.quantidade - a.quantidade);

    const total = faixas.reduce((soma, s) => soma + s.quantidade, 0);

    return { faixas, total };
});

const maiorIlha = computed(() =>
    Math.max(1, ...preenchidos.value.map((s) => s.pessoas.length)),
);

const pessoaAtiva = computed(() =>
    props.quadro.pessoas.find((p) => p.id === pessoaAtivaId.value) ?? null,
);

function cor(codigo) {
    return corDoSegmento(codigo);
}

function larguraBarra(quantidade) {
    return `${Math.max(8, (quantidade / maiorIlha.value) * 100)}%`;
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

function soltarEm(segmentoId) {
    const pessoa = arrastado.value;
    arrastado.value = null;
    alvo.value = null;
    if (!pessoa || !props.podeEditar || ehRepresentante(pessoa)) {
        return;
    }
    if (pessoa.segmentosIds.includes(segmentoId)) {
        return;
    }
    salvar(pessoa, [...pessoa.segmentosIds, segmentoId]);
}

function tirar(pessoa, segmentoId) {
    if (!props.podeEditar || ehRepresentante(pessoa)) {
        return;
    }
    salvar(pessoa, pessoa.segmentosIds.filter((id) => id !== segmentoId));
}

function abrir(pessoa) {
    pessoaAtivaId.value = pessoa.id;
}

const codigoSuper = computed(
    () => props.quadro.codigoSupermercadista || CODIGO_SUPERMERCADISTA,
);
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

        <div v-if="mosaico.total" class="overflow-hidden rounded border border-gray-300 bg-white">
            <div class="flex h-14 w-full sm:h-16">
                <div
                    v-for="faixa in mosaico.faixas"
                    :key="faixa.id"
                    class="relative min-w-[4px] overflow-hidden transition hover:brightness-95"
                    :style="{ flexGrow: faixa.quantidade, backgroundColor: cor(faixa.codigo).barra }"
                    :title="`${faixa.nome} · ${faixa.quantidade}`"
                >
                    <span
                        v-if="faixa.quantidade / mosaico.total >= 0.08"
                        class="absolute inset-0 flex items-center justify-center px-1 text-center text-[0.62rem] font-semibold uppercase leading-3 tracking-wide text-white"
                    >
                        {{ faixa.nome }}
                        <span class="ml-1 tabular-nums opacity-80">{{ faixa.quantidade }}</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-1 sm:max-w-xs">
            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Buscar</label>
            <input
                v-model="busca"
                type="text"
                placeholder="Nome ou código..."
                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan sm:min-h-0"
            />
        </div>

        <p v-if="podeEditar" class="text-xs text-gray-500">
            Arraste uma pessoa para o segmento — ou toque nela para escolher. No celular, só o toque.
        </p>
        <p v-else class="text-xs text-gray-500">Toque na pessoa para ver os segmentos dela.</p>

        <section
            v-if="semSegmento.length"
            class="rounded border-2 border-amber bg-amber/10 p-3 sm:p-4"
            :class="alvo === 'sem' ? 'ring-2 ring-amber' : ''"
        >
            <div class="mb-3 flex items-end justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-amber-dark">Sem segmento</h2>
                    <p class="text-xs text-gray-600">Ainda não atendem nenhum. Arraste para uma ilha abaixo.</p>
                </div>
                <span class="text-2xl font-bold tabular-nums text-amber-dark">{{ semSegmento.length }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <PessoaSegmentoChip
                    v-for="pessoa in semSegmento"
                    :key="pessoa.id"
                    :pessoa="pessoa"
                    :pode-editar="podeEditar"
                    :arrastando="arrastado?.id === pessoa.id"
                    @clicar="abrir"
                    @arrastar-inicio="arrastado = $event"
                    @arrastar-fim="arrastado = null"
                />
            </div>
        </section>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <section
                v-for="ilha in preenchidos"
                :key="ilha.id"
                class="flex flex-col overflow-hidden rounded border bg-white shadow-sm transition"
                :class="[
                    alvo === ilha.id ? 'ring-2 ring-cyan' : '',
                    ilha.pessoas.length >= 8 ? 'sm:col-span-2' : '',
                ]"
                :style="{ borderColor: cor(ilha.codigo).barra }"
                @dragover.prevent="alvo = ilha.id"
                @dragleave="alvo === ilha.id && (alvo = null)"
                @drop.prevent="soltarEm(ilha.id)"
            >
                <header
                    class="flex items-start justify-between gap-2 px-3 py-2.5"
                    :style="{ backgroundColor: cor(ilha.codigo).fundo, color: cor(ilha.codigo).texto }"
                >
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold uppercase tracking-wide">{{ ilha.nome }}</h2>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/60">
                            <div
                                class="h-full rounded-full"
                                :style="{ width: larguraBarra(ilha.pessoas.length), backgroundColor: cor(ilha.codigo).barra }"
                            />
                        </div>
                    </div>
                    <span class="text-2xl font-bold tabular-nums leading-none">{{ ilha.pessoas.length }}</span>
                </header>
                <div class="flex flex-wrap content-start gap-2 p-3">
                    <div
                        v-for="pessoa in ilha.pessoas"
                        :key="pessoa.id"
                        class="group relative"
                    >
                        <PessoaSegmentoChip
                            :pessoa="pessoa"
                            :pode-editar="podeEditar"
                            :arrastando="arrastado?.id === pessoa.id"
                            :travada="ehRepresentante(pessoa) && ilha.codigo === codigoSuper"
                            @clicar="abrir"
                            @arrastar-inicio="arrastado = $event"
                            @arrastar-fim="arrastado = null"
                        />
                        <button
                            v-if="podeEditar && !ehRepresentante(pessoa)"
                            type="button"
                            class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full border border-gray-300 bg-white text-[0.65rem] leading-none text-gray-500 opacity-0 transition hover:border-red-400 hover:text-red-600 group-hover:opacity-100"
                            title="Tirar deste segmento"
                            @click.stop="tirar(pessoa, ilha.id)"
                        >
                            ×
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <section v-if="vazios.length" class="rounded border border-dashed border-gray-300 bg-white p-3">
            <h2 class="mb-2 text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500">
                Ainda sem ninguém
            </h2>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="segmento in vazios"
                    :key="segmento.id"
                    type="button"
                    class="rounded border px-2.5 py-1.5 text-left text-[0.65rem] font-semibold uppercase tracking-wide transition"
                    :class="alvo === segmento.id ? 'ring-2 ring-cyan' : ''"
                    :style="{
                        backgroundColor: cor(segmento.codigo).fundo,
                        borderColor: cor(segmento.codigo).barra,
                        color: cor(segmento.codigo).texto,
                    }"
                    :title="podeEditar ? 'Solte uma pessoa aqui para atribuir' : segmento.nome"
                    @dragover.prevent="alvo = segmento.id"
                    @dragleave="alvo === segmento.id && (alvo = null)"
                    @drop.prevent="soltarEm(segmento.id)"
                >
                    {{ segmento.nome }}
                </button>
            </div>
        </section>

        <EditarSegmentosModal
            :show="Boolean(pessoaAtiva)"
            :pessoa="pessoaAtiva"
            :segmentos="quadro.segmentos"
            :codigo-supermercadista="codigoSuper"
            :salvando="salvando"
            @close="pessoaAtivaId = null"
            @salvar="pessoaAtiva && salvar(pessoaAtiva, $event)"
        />
    </div>
</template>
