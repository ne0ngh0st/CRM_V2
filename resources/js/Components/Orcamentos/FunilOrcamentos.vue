<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/*
 * O funil de orçamentos: em que etapa está cada orçamento, do pedido de margem ao pedido
 * no SIC. Substituiu os tiles soltos (2026-10-15, "mais confunde do que qualquer outra
 * coisa"): cada orçamento cai em UMA etapa só (`Orcamento::sqlEtapa()`), então as etapas
 * somam o total — o número de cima nunca contradiz os de baixo.
 *
 * A esteira (as quatro primeiras) é desenhada em sequência, com seta; "Perdido" fica à
 * parte, porque é saída do caminho, não um passo dele. Clicar numa etapa filtra a lista;
 * clicar de novo remove — a página monta `href`/`ativo`, o componente só desenha.
 */
const props = defineProps({
    // [{ chave, rotulo, qtd, valor, href, ativo }], na ordem de Orcamento::ETAPAS.
    etapas: { type: Array, required: true },
    total: { type: Number, required: true },
    valorTotal: { type: Number, required: true },
    // Fila de quem aprova ("3 com você"), dentro de "Aguardando margem". null para os demais.
    comVoce: { type: Object, default: null },
});

// A cor diz o que a etapa pede: esperando (âmbar), pode agir (cyan), concluído (verde), saiu (vermelho).
const TONS = {
    aguardando_margem: { ponto: 'bg-amber', texto: 'text-amber-dark', borda: 'border-amber' },
    aguardando_cliente: { ponto: 'bg-amber', texto: 'text-amber-dark', borda: 'border-amber' },
    pronto: { ponto: 'bg-cyan', texto: 'text-cyan-dark', borda: 'border-cyan' },
    pedido: { ponto: 'bg-emerald-500', texto: 'text-emerald-700', borda: 'border-emerald-500' },
    perdido: { ponto: 'bg-red-500', texto: 'text-red-600', borda: 'border-red-500' },
};

const esteira = computed(() => props.etapas.filter((e) => e.chave !== 'perdido'));
const perdido = computed(() => props.etapas.find((e) => e.chave === 'perdido'));

const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });
const numero = new Intl.NumberFormat('pt-BR');
</script>

<template>
    <section class="rounded border border-gray-300 bg-white p-3" aria-label="Funil de orçamentos">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-stretch">
            <!-- Celular: duas colunas (empilhado em uma só passava de 470px de altura). -->
            <ol class="grid flex-1 grid-cols-2 gap-2 sm:flex sm:flex-row sm:items-stretch">
                <li v-for="(etapa, i) in esteira" :key="etapa.chave" class="flex flex-1 items-stretch gap-2">
                    <Link
                        :href="etapa.href"
                        preserve-scroll
                        class="flex min-w-0 flex-1 flex-col rounded border px-3 py-2 transition hover:border-navy hover:bg-white hover:shadow-sm"
                        :class="etapa.ativo ? [TONS[etapa.chave].borda, 'bg-white shadow-sm'] : 'border-gray-200 bg-gray-50'"
                        :title="etapa.ativo ? 'Filtro aplicado — clique para remover' : `Ver os orçamentos em “${etapa.rotulo}”`"
                        :aria-current="etapa.ativo ? 'true' : undefined"
                    >
                        <span class="flex items-center gap-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">
                            <span class="h-2 w-2 shrink-0 rounded-full" :class="TONS[etapa.chave].ponto" />
                            <span class="leading-tight sm:truncate" :class="etapa.ativo ? TONS[etapa.chave].texto : ''">{{ etapa.rotulo }}</span>
                            <span v-if="etapa.ativo" aria-hidden="true" class="font-normal opacity-70">✕</span>
                        </span>
                        <span class="mt-1 text-xl font-bold leading-tight" :class="TONS[etapa.chave].texto">{{ numero.format(etapa.qtd) }}</span>
                        <span class="text-xs text-gray-500">{{ moeda.format(etapa.valor) }}</span>
                    </Link>
                    <!-- Seta entre etapas: só onde elas ficam lado a lado. -->
                    <span v-if="i < esteira.length - 1" aria-hidden="true" class="hidden shrink-0 items-center text-gray-300 sm:flex">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                            <path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                </li>
            </ol>

            <div v-if="perdido" class="flex lg:border-l lg:border-gray-200 lg:pl-2">
                <Link
                    :href="perdido.href"
                    preserve-scroll
                    class="flex min-w-0 flex-1 flex-col rounded border px-3 py-2 transition hover:border-navy hover:bg-white hover:shadow-sm lg:w-44 lg:flex-none"
                    :class="perdido.ativo ? [TONS.perdido.borda, 'bg-white shadow-sm'] : 'border-gray-200 bg-gray-50'"
                    :title="perdido.ativo ? 'Filtro aplicado — clique para remover' : 'Margem rejeitada ou cliente recusou'"
                    :aria-current="perdido.ativo ? 'true' : undefined"
                >
                    <span class="flex items-center gap-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">
                        <span class="h-2 w-2 shrink-0 rounded-full" :class="TONS.perdido.ponto" />
                        <span :class="perdido.ativo ? TONS.perdido.texto : ''">{{ perdido.rotulo }}</span>
                        <span v-if="perdido.ativo" aria-hidden="true" class="font-normal opacity-70">✕</span>
                    </span>
                    <span class="mt-1 text-xl font-bold leading-tight" :class="TONS.perdido.texto">{{ numero.format(perdido.qtd) }}</span>
                    <span class="text-xs text-gray-500">{{ moeda.format(perdido.valor) }}</span>
                </Link>
            </div>
        </div>

        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
            <span>
                <strong class="font-semibold text-gray-700">{{ numero.format(total) }}</strong>
                orçamento{{ total !== 1 ? 's' : '' }} · {{ moeda.format(valorTotal) }}
            </span>
            <Link
                v-if="comVoce"
                :href="comVoce.href"
                preserve-scroll
                class="rounded border px-1.5 py-0.5 font-medium transition hover:border-amber"
                :class="comVoce.ativo ? 'border-amber bg-amber/10 text-amber-dark' : 'border-gray-200 text-gray-600'"
            >
                {{ numero.format(comVoce.qtd) }} aguardando a sua aprovação
                <span v-if="comVoce.ativo" aria-hidden="true" class="opacity-70">✕</span>
            </Link>
        </p>
    </section>
</template>
