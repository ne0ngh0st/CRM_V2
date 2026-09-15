<script setup>
/**
 * Cabeçalho de página. Usado por 18 páginas, então toda decisão daqui vale para o
 * sistema inteiro — inclusive as de largura.
 *
 * ⚠️ ABAIXO DE `sm` O CABEÇALHO EMPILHA, e isso não é preferência estética: é a única
 * forma determinística de o título não ser esmagado. Até 2026-09-15 a faixa era
 * `flex flex-wrap justify-between` com o título em `min-w-0 flex-1` e as pills em
 * `shrink-0`. `min-w-0` declara que aquela coluna pode encolher até ZERO, e o flexbox
 * só quebra linha quando os itens não caberiam no tamanho mínimo deles — com mínimo
 * zero, ele nunca quebra: prefere comprimir o título. A 320px as duas pills do Painel
 * (`whitespace-nowrap`) pediam ~230px, sobravam ~70px para o título, e o resultado era
 * "Painel / Comercial" em duas linhas com o subtítulo descendo UMA PALAVRA POR LINHA.
 *
 * A regra geral, que vale em qualquer lugar deste projeto: `min-w-0 flex-1` ao lado de
 * `shrink-0` num container `flex-wrap` não quebra linha, esmaga. Quando os dois lados
 * precisam de largura, empilhe no mobile em vez de confiar no wrap.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────
 * OS FILTROS COLAPSAM NO CELULAR (2026-09-15)
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * A Carteira tem sete `FilterField` mais a busca: a 320px isso é uma coluna de quase
 * uma tela inteira, e a tabela — que é o conteúdo — começava abaixo da primeira dobra.
 * Abaixo de 640px a faixa vira um botão "Filtros (N)" que abre em `ModalPadrao`.
 *
 * Dois slots, porque nem todo controle deve colapsar:
 *   `#filtrosFixos`  fica SEMPRE visível (a busca, o "Ordenar por" do celular). Filtrar
 *                    é ocasional; buscar e ordenar são o uso normal da tela.
 *   `#filtros`       colapsa no celular, inline a partir de `sm`.
 *
 * ⚠️ `filtrosAtivos` NÃO É ENFEITE. Com a faixa escondida, ele é a única pista de que a
 * lista está recortada — sem o número, o usuário lê uma lista filtrada como se fosse a
 * base inteira. Toda página que use `#filtros` passa a contagem.
 *
 * ⚠️ O modal é `v-if="compacta"` e o inline é `v-else`: só UM dos dois existe a cada
 * momento. Fazer isso com `sm:hidden`/`hidden sm:flex` renderizaria o slot duas vezes,
 * com dois `<select>` para o mesmo filtro na mesma árvore.
 *
 * ⚠️ Os filtros aplicam sozinhos, a cada mudança, com `preserveState: true` — então o
 * modal SOBREVIVE à visita do Inertia e dá para escolher vários filtros seguidos sem
 * reabrir. Se algum dia uma dessas telas trocar para `preserveState: false`, o modal
 * fechará a cada seleção e vai parecer defeito daqui.
 */
import { ref } from 'vue';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import { useTelaCompacta } from '@/composables/useTelaCompacta';

defineProps({
    title: { type: String, required: true },

    /*
     * Quantos filtros estão aplicados. É o que o botão do celular mostra, e é
     * OBRIGATÓRIO em toda página que use `#filtros`: com a faixa colapsada, este número
     * é a única pista de que a lista está recortada. Contar é `utils/filtros.js`.
     */
    filtrosAtivos: { type: Number, default: 0 },
});

const { compacta } = useTelaCompacta();
const filtrosAbertos = ref(false);
</script>

<template>
    <div class="mb-4 overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <div
            class="flex flex-col gap-2 bg-corp-black px-4 py-3.5 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between sm:gap-3"
        >
            <div class="min-w-0 sm:flex-1">
                <h1 class="flex items-start gap-2 text-lg font-bold leading-tight text-gray-100">
                    <span v-if="$slots.icon" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400">
                        <slot name="icon" />
                    </span>
                    <span class="min-w-0">{{ title }}</span>
                </h1>
                <p v-if="$slots.subtitle" class="mt-1 max-w-prose text-sm leading-snug text-gray-400">
                    <slot name="subtitle" />
                </p>
            </div>
            <!--
                `justify-end` só a partir de `sm`: empilhado, pill alinhada à direita fica
                órfã do título que ela qualifica.
            -->
            <div v-if="$slots.meta" class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                <slot name="meta" />
            </div>
        </div>
        <div
            v-if="$slots.filtros || $slots.filtrosFixos"
            class="flex flex-wrap items-end gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3"
        >
            <slot name="filtrosFixos" />

            <slot v-if="! compacta" name="filtros" />

            <!--
                Alvo de 44px (`min-h-11`) porque no celular este botão é o único caminho
                para os filtros. A contagem fica numa pastilha cyan — a mesma cor que o
                sistema já usa para "ativo" na navegação.
            -->
            <button
                v-else-if="$slots.filtros"
                type="button"
                class="flex min-h-11 w-full items-center justify-center gap-2 rounded border border-gray-300 bg-white px-3 text-xs font-semibold uppercase tracking-wide text-gray-600 transition hover:bg-gray-100"
                @click="filtrosAbertos = true"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 shrink-0">
                    <path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round" />
                </svg>
                Filtros
                <span
                    v-if="filtrosAtivos"
                    class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-cyan px-1.5 text-[0.65rem] font-bold text-white"
                >
                    {{ filtrosAtivos }}
                </span>
            </button>
        </div>
    </div>

    <ModalPadrao
        v-if="compacta && $slots.filtros"
        :show="filtrosAbertos"
        titulo="Filtros"
        :subtitulo="filtrosAtivos ? `${filtrosAtivos} aplicado${filtrosAtivos > 1 ? 's' : ''}` : 'Nenhum aplicado'"
        @close="filtrosAbertos = false"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                <path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round" />
            </svg>
        </template>

        <!-- Empilhado: dentro do modal não há largura para duas colunas de filtro, e o
             `flex-wrap` da faixa deixaria pares de campos com metades desiguais. -->
        <div class="flex flex-col gap-3">
            <slot name="filtros" />
        </div>

        <template #footer>
            <button
                type="button"
                class="min-h-11 rounded bg-navy px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-white transition hover:bg-navy/90 sm:min-h-0"
                @click="filtrosAbertos = false"
            >
                Ver resultados
            </button>
        </template>
    </ModalPadrao>
</template>
