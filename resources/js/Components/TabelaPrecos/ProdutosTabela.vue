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
    <div class="overflow-x-auto">
        <table class="w-full min-w-[700px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="px-3 py-2.5 font-semibold">Código</th>
                    <th class="px-3 py-2.5 font-semibold">Descrição</th>
                    <th class="px-3 py-2.5 font-semibold">Categoria</th>
                    <th class="px-3 py-2.5 font-semibold">Unidade</th>
                    <th class="px-3 py-2.5 font-semibold">Preço tabela</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr
                    v-for="produto in produtos"
                    :key="produto.id"
                    class="divide-x divide-gray-200 hover:bg-gray-50/60"
                >
                    <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">
                        {{ produto.codProduto }}
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-700">
                        <span class="mx-auto block max-w-[360px] truncate" :title="produto.descricao">
                            {{ produto.descricao }}
                        </span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">
                        {{ produto.categoria || '—' }}
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-500">
                        {{ produto.unidade || '—' }}
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle font-semibold"
                        :class="produto.precoTabela ? 'text-gray-800' : 'text-gray-400'"
                    >
                        {{ formatBRL(produto.precoTabela) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
