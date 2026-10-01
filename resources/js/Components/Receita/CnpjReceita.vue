<script setup>
/*
 * O CNPJ, marcado quando está irregular na Receita — sem ocupar linha nenhuma a mais.
 *
 * Primeira versão era uma pill vermelha abaixo do status, e o Tony recusou (2026-10-01):
 * "poluiu muito a página". A informação é SOBRE o CNPJ, então mora nele: o número fica
 * vermelho, ganha um ícone de alerta pequeno, e o detalhe (situação, desde quando, mês da
 * base) vai para o tooltip. CNPJ ativo ou nunca verificado aparece exatamente como antes.
 *
 * Quem decide o que é irregular é o servidor (`receita.irregular`, de
 * `SituacaoCadastral::irregular()`); aqui só se desenha.
 */
import { computed } from 'vue';
import { ROTULOS_SITUACAO_RECEITA, dataDaSituacao, mesDaBase } from '@/constants/receita.js';

const props = defineProps({
    cnpj: { type: String, default: null },
    receita: { type: Object, default: null },
    // 'dark' no cabeçalho preto da ficha: o red-700 some sobre preto.
    surface: { type: String, default: 'light' },
});

const irregular = computed(() => props.receita?.irregular === true);

const titulo = computed(() => {
    if (!irregular.value) return null;
    const situacao = ROTULOS_SITUACAO_RECEITA[props.receita.situacao] ?? props.receita.situacao;
    const desde = dataDaSituacao(props.receita.data);
    const base = props.receita.referencia ? ` (base de ${mesDaBase(props.receita.referencia)})` : '';
    return `${situacao} na Receita${desde ? ` desde ${desde}` : ''}${base}`;
});
</script>

<template>
    <span
        :class="irregular ? ['inline-flex items-center gap-0.5 font-medium', surface === 'dark' ? 'text-red-300' : 'text-red-700'] : null"
        :title="titulo"
    >
        {{ cnpj ?? '—' }}
        <svg v-if="irregular" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-3 w-3 shrink-0" aria-hidden="true">
            <path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linejoin="round" />
        </svg>
        <span v-if="irregular" class="sr-only">{{ titulo }}</span>
    </span>
</template>
