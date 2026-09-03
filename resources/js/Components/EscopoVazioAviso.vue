<script setup>
/**
 * Explica a tela vazia do supervisor sem equipe, em vez de deixá-la em branco.
 *
 * ⚠️ CASO REAL EM PRODUÇÃO: o supervisor ROBERTO (000197) tem equipe VAZIA. Como o modo
 * Equipe resolve o escopo para `[]` e `whereIn([])` significa zero linhas, ele via tela em
 * branco no sistema INTEIRO — apesar de ter 1.649 clientes no próprio nome. Sem uma frase
 * aqui, "não tenho nada" é indistinguível de "o sistema quebrou".
 *
 * Só aparece para quem tem o alternador (supervisor) e está em modo Equipe: para os outros
 * perfis uma lista vazia significa mesmo que não há nada, e a mensagem seria ruído.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** Quantos registros a tela encontrou no escopo atual. */
    total: { type: Number, required: true },
    /** O que a tela lista, para a frase não ficar genérica. */
    recurso: { type: String, default: 'registro' },
});

const page = usePage();

const mostrar = computed(
    () => props.total === 0
        && page.props.modoVisao?.disponivel
        && page.props.modoVisao?.modo === 'equipe',
);
</script>

<template>
    <div v-if="mostrar" class="mb-3 rounded border border-amber/40 bg-amber/10 px-3 py-2 text-sm text-gray-700">
        <p class="font-semibold">Nenhum {{ recurso }} na sua equipe.</p>
        <p class="mt-0.5 text-xs text-gray-600">
            Se você atende clientes diretamente, troque para
            <strong>Minha carteira</strong> no topo da tela para ver os seus.
        </p>
    </div>
</template>
