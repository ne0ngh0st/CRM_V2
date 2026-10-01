<script setup>
/*
 * O CNPJ, marcado quando está irregular na Receita — sem ocupar linha nenhuma a mais.
 *
 * Primeira versão era uma pill vermelha abaixo do status, e o Tony recusou (2026-10-01):
 * "poluiu muito a página". A informação é SOBRE o CNPJ, então mora nele: o número fica
 * vermelho, ganha um ícone de alerta pequeno, e o detalhe vai para o tooltip. CNPJ ativo
 * ou nunca verificado aparece exatamente como antes.
 *
 * Irregular, o CNPJ é CLICÁVEL (emite `abrir`): quem usa abre o Cartão CNPJ, e é lá dentro
 * que mora o "Solicitar inativação". Pelo mesmo motivo da pill — nada de botão a mais na
 * Carteira (Tony, mesmo dia).
 *
 * Quem decide o que é irregular é o servidor (`receita.irregular`, de
 * `SituacaoCadastral::irregular()`); aqui só se desenha.
 */
import { computed } from 'vue';
import { ROTULOS_SITUACAO_RECEITA, dataDaSituacao, mesDaBase } from '@/constants/receita.js';

const props = defineProps({
    cnpj: { type: String, default: null },
    receita: { type: Object, default: null },
    // Pedido de inativação já enviado ao Cadastro (`{em, por}`), ou null.
    inativacao: { type: Object, default: null },
    // 'dark' no cabeçalho preto da ficha: o red-700 some sobre preto.
    surface: { type: String, default: 'light' },
});

const emit = defineEmits(['abrir']);

const irregular = computed(() => props.receita?.irregular === true);

const titulo = computed(() => {
    if (!irregular.value) return null;
    const situacao = ROTULOS_SITUACAO_RECEITA[props.receita.situacao] ?? props.receita.situacao;
    const desde = dataDaSituacao(props.receita.data);
    const base = props.receita.referencia ? ` (base de ${mesDaBase(props.receita.referencia)})` : '';
    const pedido = props.inativacao
        ? `\nInativação solicitada ao Cadastro em ${props.inativacao.em}${props.inativacao.por ? ` por ${props.inativacao.por}` : ''}.`
        : '\nClique para ver o cartão CNPJ e solicitar a inativação.';
    return `${situacao} na Receita${desde ? ` desde ${desde}` : ''}${base}.${pedido}`;
});
</script>

<template>
    <button
        v-if="irregular"
        type="button"
        class="inline-flex items-center gap-0.5 font-medium underline decoration-dotted underline-offset-2 hover:decoration-solid"
        :class="surface === 'dark' ? 'text-red-300' : 'text-red-700'"
        :title="titulo"
        @click.stop="emit('abrir')"
    >
        {{ cnpj ?? '—' }}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-3 w-3 shrink-0" aria-hidden="true">
            <path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linejoin="round" />
        </svg>
        <span class="sr-only">{{ titulo }}</span>
    </button>
    <span v-else>{{ cnpj ?? '—' }}</span>
</template>
