<script setup>
/**
 * Os três seletores de visão do gestor, na ordem da hierarquia: Direção → Supervisão →
 * Vendedor. Usado dentro do slot `#filtros` do `PageHero`.
 *
 * Existe porque esta marcação estava copiada em cinco páginas (Carteira, Leads,
 * Orçamentos e as duas de Pedidos): um nível novo no filtro era editar as cinco e torcer
 * para não esquecer nenhuma (Regra de ouro nº 8). O Painel usa `Dashboard/VisaoSelector`,
 * que navega por conta própria em vez de escrever num objeto de filtros.
 *
 * `visao` é a prop que o servidor monta em `DashboardScopeResolver::visaoParaTela()`: as
 * listas já vêm recortadas pelo que está escolhido no nível de cima.
 *
 * ⚠️ `filtros` é o objeto REACTIVE da página, e é escrito aqui de propósito: é dele que a
 * página monta a query string (`visao_diretor`, `visao_supervisor`, `visao_vendedor`).
 * Trocar um nível limpa os de baixo — a seleção antiga pode não existir na lista nova.
 */
import FilterField from '@/Components/FilterField.vue';

const props = defineProps({
    visao: { type: Object, required: true },
    filtros: { type: Object, required: true },
});

const emit = defineEmits(['aplicar']);

const NIVEIS = ['visao_diretor', 'visao_supervisor', 'visao_vendedor'];

function escolher(nivel, valor) {
    const posicao = NIVEIS.indexOf(nivel);

    props.filtros[nivel] = valor;
    NIVEIS.slice(posicao + 1).forEach((abaixo) => { props.filtros[abaixo] = ''; });

    emit('aplicar');
}
</script>

<template>
    <FilterField
        v-if="visao.diretores?.length"
        label="Direção"
        :model-value="filtros.visao_diretor"
        @update:model-value="(v) => escolher('visao_diretor', v)"
    >
        <option value="">Todas as Diretorias</option>
        <option v-for="d in visao.diretores" :key="d.cod_vendedor" :value="d.cod_vendedor">{{ d.nome }}</option>
    </FilterField>

    <FilterField
        v-if="visao.supervisores?.length"
        label="Supervisão"
        :model-value="filtros.visao_supervisor"
        @update:model-value="(v) => escolher('visao_supervisor', v)"
    >
        <option value="">Todas as Equipes</option>
        <option v-for="s in visao.supervisores" :key="s.cod_vendedor" :value="s.cod_vendedor">{{ s.nome }}</option>
    </FilterField>

    <FilterField
        v-if="visao.mostrarSeletor"
        label="Vendedor"
        :model-value="filtros.visao_vendedor"
        @update:model-value="(v) => escolher('visao_vendedor', v)"
    >
        <option value="">Todos os Vendedores</option>
        <option v-for="v in visao.vendedores" :key="v.cod_vendedor + v.nome" :value="v.cod_vendedor">{{ v.nome }}</option>
    </FilterField>
</template>
