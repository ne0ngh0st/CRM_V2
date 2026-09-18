<script setup>
/**
 * As três visões da Equipe. Mora aqui e não nas duas páginas porque Lista /
 * Organograma / Segmentos precisam acender a mesma aba nos dois lados —
 * copiar os botões é o que fez o link admin "Atualização de dados" existir
 * só no desktop (Regra de ouro nº 8).
 *
 * Organograma continua só para quem gerencia usuários (admin/diretor).
 * Segmentos é cobertura comercial: supervisor também entra, porque é ele
 * quem atribui quem atende o quê.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    ativa: { type: String, required: true },
    podeVerOrganograma: { type: Boolean, default: false },
    queryLista: { type: Object, default: () => ({}) },
});

const queryLimpa = computed(() => {
    const query = {};
    Object.entries(props.queryLista).forEach(([chave, valor]) => {
        if (valor) {
            query[chave] = valor;
        }
    });
    return query;
});

function classe(destaque) {
    return [
        'rounded border px-3 py-1.5 text-sm font-medium',
        destaque
            ? 'border-teal bg-teal text-white'
            : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50',
    ];
}
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <Link :href="route('equipe.index', queryLimpa)" :class="classe(ativa === 'lista')">
            Lista de Usuários
        </Link>
        <Link
            v-if="podeVerOrganograma"
            :href="route('equipe.index', { ...queryLimpa, aba: 'organograma' })"
            :class="classe(ativa === 'organograma')"
        >
            Organograma
        </Link>
        <Link :href="route('equipe.segmentos')" :class="classe(ativa === 'segmentos')">
            Segmentos
        </Link>
    </div>
</template>
