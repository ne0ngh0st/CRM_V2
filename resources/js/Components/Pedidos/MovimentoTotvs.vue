<script setup>
/*
 * O último movimento do pedido no TOTVS, com a data em que aconteceu.
 *
 * ⚠️ É O QUE TORNA A CLASSIFICAÇÃO NÃO-DESTRUTIVA. A pill traduz o movimento para uma
 * etapa ("Bloqueio de estoque"); aqui fica a frase exata que o TOTVS escreveu, incluindo
 * o número da carga, da ordem de separação ou da nota vencida — que é o dado que o
 * vendedor repassa quando liga para o PCP ou para o financeiro. Também é o que salva o
 * caso em que o CRM não reconhece o molde: sem pill, mas com o texto aqui.
 *
 * ⚠️ A DATA NÃO É DECORAÇÃO. Sem ela, "bloqueio de estoque" pode ser de hoje cedo ou de
 * três semanas atrás, e a ação do vendedor é diferente em cada caso. Também é o que
 * mantém honesto o movimento guardado num pedido já faturado — ele mostra por onde o
 * pedido passou antes de sair, com a data, em vez de parecer o estado atual.
 */
defineProps({
    movimento: { type: String, default: null },
    movimentoEm: { type: String, default: null },
});
</script>

<template>
    <div v-if="movimento">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Último movimento no TOTVS</p>
        <p class="mt-1 text-sm text-gray-700">{{ movimento }}</p>
        <p v-if="movimentoEm" class="mt-0.5 text-[0.65rem] text-gray-400">{{ movimentoEm }}</p>
    </div>
</template>
