<script setup>
import { router } from '@inertiajs/vue3';
import FilterField from '@/Components/FilterField.vue';

const props = defineProps({
    visao: {
        type: Object,
        required: true,
    },
});

function atualizar({ supervisor, vendedor }) {
    router.get(
        route('dashboard'),
        {
            visao_supervisor: supervisor !== undefined ? (supervisor || null) : (props.visao.visaoSupervisor || null),
            visao_vendedor: vendedor !== undefined ? (vendedor || null) : (props.visao.visaoVendedor || null),
        },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <FilterField
        v-if="visao.supervisores.length"
        label="Supervisão"
        :model-value="visao.visaoSupervisor || ''"
        @update:model-value="atualizar({ supervisor: $event, vendedor: '' })"
    >
        <option value="">Todas as Equipes</option>
        <option
            v-for="s in visao.supervisores"
            :key="s.cod_vendedor"
            :value="s.cod_vendedor"
        >
            {{ s.nome }}
        </option>
    </FilterField>

    <FilterField
        label="Vendedor"
        :model-value="visao.visaoVendedor || ''"
        @update:model-value="atualizar({ vendedor: $event })"
    >
        <option value="">Todos os Vendedores</option>
        <option
            v-for="v in visao.vendedores"
            :key="v.cod_vendedor"
            :value="v.cod_vendedor"
        >
            {{ v.nome }}
        </option>
    </FilterField>
</template>
