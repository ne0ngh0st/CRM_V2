<script setup>
/**
 * Card padrão do sistema: header preto sólido + corpo branco.
 *
 * ⚠️ SLOT `#detalhes` (2026-09-06): quatro cards do Painel ficaram altos demais. O que
 * some não é o card — é a PARTE DE BAIXO dele. O principal (os KPIs, o número que se lê de
 * relance) fica sempre visível; a lista de registros recentes entra neste slot e abre no
 * clique.
 *
 * A capacidade mora AQUI, e não em cada card, porque são quatro lugares querendo o mesmo
 * comportamento — copiar estado, botão e persistência quatro vezes é como a densidade das
 * tabelas driftou entre 14 arquivos antes dos tokens `.tbl` (Regra de ouro nº 8).
 *
 * ⚠️ Recolher o CARD INTEIRO foi a primeira tentativa e estava errada por dois motivos:
 * escondia o número que a pessoa quer ver de relance, e, dentro de um grid
 * `items-stretch`, o card recolhido continuava ocupando a altura da fileira — recolher um
 * de três não mudava nada na tela.
 *
 * ⚠️ O estado é POR PESSOA E POR NAVEGADOR (`localStorage`), não do servidor: quem abre o
 * detalhe quer encontrá-lo aberto amanhã, e isso é conveniência de quem olha, não dado de
 * negócio. Toda leitura e escrita vai dentro de try/catch — em janela anônima ou
 * com cookies de site bloqueados o acessor ESTOURA, e um card não pode derrubar a página
 * por causa disso.
 *
 * ⚠️ `chaveDetalhes` é obrigatória para persistir. Sem ela o detalhe ainda abre e fecha,
 * mas esquece ao recarregar — de propósito: chave derivada do título quebraria em silêncio
 * quando o título mudasse, e os títulos daqui mudam (o "Potencial da Carteira" virou
 * "Segmentos Atendidos" nesta mesma semana).
 */
import { ref, watch } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Rótulo do botão que abre o slot `#detalhes`. */
    rotuloDetalhes: { type: String, default: 'Ver detalhes' },
    /** Identificador estável para lembrar o estado. Sem ela, não persiste. */
    chaveDetalhes: { type: String, default: null },
    /**
     * Corpo mais baixo. O `p-4` padrão é o respiro dos cards de KPI (tile, anel, lista
     * de registros). Faixa de ranking — uma tabela densa de ponta a ponta — não precisa
     * da mesma folga: o conteúdo já é a leitura, e cada 8px de padding vira altura de
     * página. Default permanece `p-4` para ninguém encolher sem pedir.
     */
    compacto: { type: Boolean, default: false },
});

const PREFIXO = 'card-detalhes:';

/** Detalhe nasce FECHADO: o motivo do slot existir é devolver altura à página. */
function lerPreferencia() {
    if (!props.chaveDetalhes) {
        return false;
    }

    try {
        return window.localStorage.getItem(PREFIXO + props.chaveDetalhes) === '1';
    } catch {
        return false;
    }
}

const detalhesAbertos = ref(lerPreferencia());

watch(detalhesAbertos, (valor) => {
    if (!props.chaveDetalhes) {
        return;
    }

    try {
        window.localStorage.setItem(PREFIXO + props.chaveDetalhes, valor ? '1' : '0');
    } catch {
        // Sem persistência é um degrade aceitável; quebrar a página não é.
    }
});
</script>

<template>
    <div class="flex h-full flex-col overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <!--
            ⚠️ Empilha abaixo de `sm` pelo mesmo motivo do `PageHero`: com o título em
            `min-w-0 flex-1` ao lado de um `#actions` em `shrink-0`, o `flex-wrap` nunca
            dispara e quem encolhe é o título. O `min-h-[3.5rem]` existe para alinhar
            headers de cards VIZINHOS numa fileira — no mobile os cards já ficam um por
            linha, então não há vizinho para alinhar e empilhar não custa nada.
        -->
        <div
            class="flex min-h-[3.5rem] flex-col gap-2 border-b border-black/40 bg-corp-black px-4 py-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-3"
        >
            <div class="min-w-0 sm:flex-1">
                <h3 class="flex items-start gap-2 text-sm font-semibold leading-tight text-gray-100">
                    <span v-if="$slots.icon" class="mt-px h-4 w-4 shrink-0 text-gray-400">
                        <slot name="icon" />
                    </span>
                    <span class="min-w-0">{{ title }}</span>
                </h3>
                <!--
                    ⚠️ O corte com reticências vale só a partir de `sm`. No celular o
                    subtítulo quebra em quantas linhas precisar: ele carrega os números que
                    o card resume ("73.940 inativos · 474.581 caixas · 26 segmentos"), e
                    truncado ele escondia justamente o conteúdo, não um enfeite.
                -->
                <p
                    v-if="subtitle"
                    class="mt-0.5 text-xs leading-snug text-gray-400 sm:overflow-hidden sm:text-ellipsis sm:whitespace-nowrap"
                >
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:shrink-0">
                <slot name="actions" />
            </div>
        </div>
        <div class="flex flex-1 flex-col" :class="compacto ? 'px-4 py-2' : 'p-4'">
            <slot />

            <template v-if="$slots.detalhes">
                <!--
                    `mt-auto` empurra o botão para o pé do card: dentro de um grid
                    `items-stretch` o card recebe a altura do vizinho mais alto, e sem isso
                    o botão flutuaria no meio do vão sobrando.
                -->
                <button
                    type="button"
                    class="mt-auto flex w-full items-center justify-center gap-1 border-t border-gray-100 pt-3 text-xs font-medium text-gray-500 transition hover:text-navy"
                    :aria-expanded="detalhesAbertos"
                    @click="detalhesAbertos = !detalhesAbertos"
                >
                    {{ detalhesAbertos ? 'Ocultar detalhes' : rotuloDetalhes }}
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="h-3 w-3 transition-transform"
                        :class="detalhesAbertos ? 'rotate-180' : ''"
                    >
                        <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <div v-if="detalhesAbertos" class="mt-3">
                    <slot name="detalhes" />
                </div>
            </template>
        </div>
    </div>
</template>
