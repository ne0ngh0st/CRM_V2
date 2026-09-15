<script setup>
import { computed } from 'vue';

/**
 * Seletor "Ordenar por" que só existe no celular.
 *
 * Ele repõe uma capacidade que o cartão tira: abaixo de 640px a tabela vira cartões e o
 * `<thead>` desaparece, levando junto os `SortableTh` — na Carteira são SEIS colunas
 * ordenáveis que ficariam inalcançáveis. Um select existiu nesta tela e foi removido em
 * 2026-08-10 por ser UI duplicada; no celular ele deixa de ser duplicado, porque o outro
 * caminho não está lá.
 *
 * ⚠️ `sm:hidden`, e o `SortableTh` sem contrapartida: cada um é o único acesso à
 * ordenação na sua largura. Se um dia os dois aparecerem juntos, é sinal de que o
 * breakpoint aqui e o do `.tbl-cartoes` no `app.css` divergiram.
 *
 * ⚠️ Emite o valor pronto (`<campo>_<asc|desc>`), o MESMO formato do `SortableTh`, para o
 * consumidor tratar os dois caminhos com um único handler. A whitelist continua sendo do
 * servidor — campo daqui que não esteja lá cai no default sem erro.
 */
const props = defineProps({
    /** @type {{ campo: string, rotulo: string }[]} */
    colunas: { type: Array, required: true },
    ordenar: { type: String, default: '' },
});

const emit = defineEmits(['ordenar']);

const atual = computed(() => {
    const partes = /^(.*)_(asc|desc)$/.exec(props.ordenar || '');

    return partes ? { campo: partes[1], direcao: partes[2] } : { campo: null, direcao: 'asc' };
});

const rotuloDirecao = computed(() => {
    const coluna = props.colunas.find((c) => c.campo === atual.value.campo);

    /*
     * "A → Z" para texto e "Mais recente" para data dizem a mesma coisa que asc/desc sem
     * exigir que a pessoa saiba o que asc/desc significa. A heurística é o nome do campo,
     * não um terceiro atributo: `status` também é uma data por baixo (a última compra em
     * faixas), então tratá-lo como data é o que descreve o resultado de verdade.
     */
    const ehData = /compra|contato|status|data/.test(coluna?.campo ?? '');

    if (atual.value.direcao === 'desc') return ehData ? 'Mais recente' : 'Z → A';

    return ehData ? 'Mais antigo' : 'A → Z';
});

function trocarCampo(campo) {
    emit('ordenar', `${campo}_${atual.value.direcao ?? 'asc'}`);
}

function inverter() {
    emit('ordenar', `${atual.value.campo ?? props.colunas[0].campo}_${atual.value.direcao === 'asc' ? 'desc' : 'asc'}`);
}
</script>

<template>
    <div class="flex items-end gap-2 sm:hidden">
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Ordenar por</label>
            <select
                :value="atual.campo ?? colunas[0].campo"
                class="min-h-11 w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                @change="trocarCampo($event.target.value)"
            >
                <option v-for="c in colunas" :key="c.campo" :value="c.campo">{{ c.rotulo }}</option>
            </select>
        </div>

        <!-- Inverter é um botão à parte, não duas opções por coluna no select: com 6
             colunas seriam 12 linhas dizendo quase a mesma coisa. O rótulo mostra o
             sentido ATUAL, então ele funciona como estado e como ação. -->
        <button
            type="button"
            class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded border border-gray-300 bg-white px-3 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
            title="Inverter a ordem"
            @click="inverter"
        >
            <svg
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                class="h-3.5 w-3.5 shrink-0 text-cyan-dark transition"
                :class="atual.direcao === 'desc' ? 'rotate-180' : ''"
            >
                <path d="m6 14 6-6 6 6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ rotuloDirecao }}
        </button>
    </div>
</template>
