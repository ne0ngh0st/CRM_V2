<script setup>
import { ref } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import StatusPedidoPill from '@/Components/Pedidos/StatusPedidoPill.vue';
import MovimentoTotvs from '@/Components/Pedidos/MovimentoTotvs.vue';
import { ROTULOS_SITUACAO_PEDIDO, TONS_SITUACAO_PEDIDO } from '@/constants/pedidos.js';

defineProps({
    pedidos: { type: Array, required: true },
});

const expandido = ref(null);

function toggle(id) {
    expandido.value = expandido.value === id ? null : id;
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}

function formatQuantidade(valor) {
    return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(valor);
}
</script>

<template>
    <div class="tbl-wrap">
        <!-- `sm:min-w-`, nunca `min-w-`: a largura mínima é utility e venceria o
             `@layer components`, então abaixo de 640px o cartão sairia certo dentro de uma
             página rolando 900px na horizontal. -->
        <table class="tbl tbl-cartoes sm:min-w-[900px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th w-8"></th>
                    <th class="tbl-th">Pedido</th>
                    <th class="tbl-th">Cliente</th>
                    <th class="tbl-th">Vendedor</th>
                    <th class="tbl-th">Data Pedido</th>
                    <th class="tbl-th">Previsão Faturamento</th>
                    <th class="tbl-th">Valor Total</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Itens</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <template v-for="pedido in pedidos" :key="pedido.id">
                    <tr
                        class="tbl-row cursor-pointer"
                        @click="toggle(pedido.id)"
                    >
                        <!-- No cartão esta célula vira o rodapé "Ver itens": ela é a única
                             pista de que a linha abre. `sm:mx-auto` no lugar de `mx-auto`
                             porque no rodapé o `.tbl-td-expandir` é flex, e margem `auto`
                             ali centralizaria a seta empurrando o rótulo para longe dela. -->
                        <td class="tbl-td tbl-td-expandir text-gray-400">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="h-3.5 w-3.5 shrink-0 transition-transform sm:mx-auto"
                                :class="expandido === pedido.id ? 'rotate-90' : ''"
                            >
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span class="sm:hidden">{{ expandido === pedido.id ? 'Ocultar itens' : `Ver ${pedido.itens.length} ${pedido.itens.length === 1 ? 'item' : 'itens'}` }}</span>
                        </td>
                        <td class="tbl-td font-medium text-gray-800" data-rotulo="Pedido">{{ pedido.numeroPedido }}</td>
                        <!-- O cliente é a manchete do cartão, não o número do pedido: quem
                             abre isto no celular está procurando "o pedido da Prefeitura",
                             e o número serve para confirmar, não para achar. -->
                        <td class="tbl-td tbl-td-titulo">
                            <span class="tbl-trunc sm:max-w-[220px]" :title="pedido.cliente?.razaoSocial">{{ pedido.cliente?.razaoSocial ?? '—' }}</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Vendedor">
                            <span class="tbl-trunc sm:max-w-[180px]" :title="pedido.vendedorNome">{{ pedido.vendedorNome }}</span>
                        </td>
                        <td class="tbl-td" data-rotulo="Data do pedido">{{ pedido.dataPedido }}</td>
                        <td class="tbl-td" data-rotulo="Previsão de faturamento">
                            <StatusPill :tone="TONS_SITUACAO_PEDIDO[pedido.situacao]" size="sm">
                                {{ pedido.dataPrevisaoFaturamento ?? 'Sem previsão' }}
                                <template v-if="pedido.diasAtraso"> · {{ pedido.diasAtraso }}d</template>
                            </StatusPill>
                        </td>
                        <td class="tbl-td font-medium text-gray-800" data-rotulo="Valor total">{{ formatBRL(pedido.valorTotal) }}</td>
                        <td class="tbl-td" data-rotulo="Status">
                            <StatusPedidoPill :status="pedido.status" :rotulo="pedido.statusRotulo" />
                        </td>
                        <!-- A contagem sai do cartão: ela já está dentro do "Ver N itens" do
                             rodapé, e repeti-la gastaria meia linha para dizer o mesmo. -->
                        <td class="tbl-td tbl-td-oculto">{{ pedido.itens.length }}</td>
                    </tr>
                    <tr v-if="expandido === pedido.id" class="bg-gray-50">
                        <td colspan="9" class="tbl-td-expansao p-2 sm:p-4">
                            <!-- ⚠️ `grid-cols-1` explícito, não só `lg:grid-cols-3`: sem ele
                                 a coluna implícita é `auto`, e `auto` nunca fica menor que o
                                 conteúdo. A `.tbl-itens` tem 560px de largura mínima no
                                 cartão, então a coluna virava 560 dentro de uma célula de
                                 276 — o bloco "Detalhes" ao lado ia junto e seus valores
                                 ficavam fora da tela, à direita, sem nada quebrar. O
                                 `grid-cols-1` do Tailwind é `minmax(0, 1fr)`, que é o piso
                                 zero que faltava. -->
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                                <div class="lg:col-span-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Itens do pedido</p>
                                    <!-- ⚠️ O scroll tem que ser DESTA tabela, não da célula
                                         de expansão: no cartão ela ganha largura mínima, e
                                         com o overflow uma camada acima o bloco "Detalhes"
                                         ao lado seria esticado junto e passaria a rolar
                                         também, sem ter o que mostrar a mais. -->
                                    <div class="tbl-wrap">
                                        <table class="tbl-itens">
                                            <thead>
                                                <tr class="tbl-itens-head-row">
                                                    <th class="tbl-itens-th">Produto</th>
                                                    <th class="tbl-itens-th">Qtd.</th>
                                                    <th class="tbl-itens-th">Qtd. Liberada</th>
                                                    <th class="tbl-itens-th">Vlr. Unit.</th>
                                                    <th class="tbl-itens-th">Vlr. Total</th>
                                                </tr>
                                            </thead>
                                            <tbody class="tbl-body">
                                                <tr v-for="(item, idx) in pedido.itens" :key="idx" class="tbl-itens-row">
                                                    <td class="tbl-itens-td">
                                                        <span v-if="item.codProduto" class="text-gray-400">{{ item.codProduto }} · </span>{{ item.descricao }}
                                                    </td>
                                                    <td class="tbl-itens-td">{{ formatQuantidade(item.quantidade) }}</td>
                                                    <td class="tbl-itens-td">
                                                        {{ item.quantidadeLiberada !== null ? formatQuantidade(item.quantidadeLiberada) : '—' }}
                                                    </td>
                                                    <td class="tbl-itens-td">{{ formatBRL(item.valorUnitario) }}</td>
                                                    <td class="tbl-itens-td font-medium text-gray-800">{{ formatBRL(item.valorTotal) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <MovimentoTotvs :movimento="pedido.movimento" :movimento-em="pedido.movimentoEm" />

                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Detalhes</p>
                                        <dl class="mt-2 space-y-1.5 text-xs text-gray-600">
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">CNPJ</dt>
                                                <dd class="text-right">{{ pedido.cliente?.cnpj ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">Telefone</dt>
                                                <dd class="text-right">{{ pedido.cliente?.telefone ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">E-mail</dt>
                                                <dd class="truncate text-right" :title="pedido.cliente?.email">{{ pedido.cliente?.email ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">Entrega prevista</dt>
                                                <dd class="text-right">{{ pedido.dataEntregaPrevista ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">Data PCP</dt>
                                                <dd class="text-right">{{ pedido.dataPcp ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">Carga</dt>
                                                <dd class="text-right">{{ pedido.carga ?? '—' }}</dd>
                                            </div>
                                            <div class="flex justify-between gap-2">
                                                <dt class="text-gray-400">Condição pagto.</dt>
                                                <dd class="text-right">{{ pedido.condicaoPagamento ?? '—' }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
