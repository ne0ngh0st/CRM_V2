<script setup>
/*
 * Pill "CNPJ irregular na Receita" — a mesma marcação na linha do cliente, na sub-linha da
 * filial e na ficha. Não aparece quando está tudo certo (ATIVA) nem quando o CNPJ nunca foi
 * verificado: a Carteira só acusa o que sabe.
 *
 * Quem decide o que é irregular é o servidor (`receita.irregular` e `receita.irregulares`,
 * de `SituacaoCadastral`). Aqui só se escolhe o texto.
 *
 * Linha agrupada (`irregulares` presente): com uma filial só, mostra a situação dela
 * ("Baixada"); com várias, quantas estão irregulares — que é o número que o filtro
 * "Receita: CNPJ irregular" usa, então pill e filtro sempre concordam.
 */
import { computed } from 'vue';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_SITUACAO_RECEITA, dataDaSituacao, mesDaBase } from '@/constants/receita.js';

const props = defineProps({
    receita: { type: Object, default: null },
    // Filiais do cliente na linha agrupada; null na filial e no modo por filial.
    lojas: { type: Number, default: null },
    size: { type: String, default: 'sm' },
    surface: { type: String, default: 'light' },
});

const porContagem = computed(() => props.receita?.irregulares !== null && props.receita?.irregulares !== undefined && (props.lojas ?? 1) > 1);

const visivel = computed(() => {
    if (!props.receita) return false;
    return porContagem.value ? props.receita.irregulares > 0 : props.receita.irregular === true;
});

const texto = computed(() => {
    if (porContagem.value) {
        const n = props.receita.irregulares;
        return n === 1 ? '1 filial irregular' : `${n} filiais irregulares`;
    }
    // "Baixada na Receita", não "CNPJ baixada": a situação é feminina, o CNPJ não.
    return `${ROTULOS_SITUACAO_RECEITA[props.receita.situacao] ?? props.receita.situacao} na Receita`;
});

const titulo = computed(() => {
    if (porContagem.value) {
        return 'Filiais com CNPJ baixado, inapto, suspenso, nulo ou inexistente na Receita. Abra as filiais para ver qual.';
    }
    const desde = dataDaSituacao(props.receita.data);
    const base = props.receita.referencia ? ` (base de ${mesDaBase(props.receita.referencia)})` : '';
    return `Situação na Receita: ${props.receita.situacao}${desde ? ` desde ${desde}` : ''}${base}`;
});
</script>

<template>
    <span v-if="visivel" :title="titulo" class="inline-flex">
        <StatusPill tone="danger" :size="size" :surface="surface">{{ texto }}</StatusPill>
    </span>
</template>
