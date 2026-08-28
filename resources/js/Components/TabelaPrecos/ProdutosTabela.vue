<script setup>
defineProps({
    produtos: { type: Array, required: true },
});

function formatBRL(valor) {
    if (valor === null || valor === undefined) return '—';
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor);
}
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl min-w-[700px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Código</th>
                    <th class="tbl-th">Descrição</th>
                    <th class="tbl-th">Categoria</th>
                    <th class="tbl-th">Unidade</th>
                    <th class="tbl-th">Preço tabela</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr
                    v-for="produto in produtos"
                    :key="produto.id"
                    class="tbl-row"
                >
                    <td class="tbl-td font-medium text-gray-800">
                        {{ produto.codProduto }}
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[360px]" :title="produto.descricao">
                            {{ produto.descricao }}
                        </span>
                    </td>
                    <td class="tbl-td">
                        {{ produto.categoria || '—' }}
                    </td>
                    <td class="tbl-td">
                        {{ produto.unidade || '—' }}
                    </td>
                    <td class="tbl-td font-medium"
                        :class="produto.precoTabela ? 'text-gray-800' : 'text-gray-400'"
                    >
                        {{ formatBRL(produto.precoTabela) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
