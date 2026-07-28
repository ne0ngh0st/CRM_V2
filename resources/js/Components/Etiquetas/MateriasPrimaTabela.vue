<script setup>
import StatusPill from '@/Components/StatusPill.vue';

defineProps({
    materiasPrimas: { type: Array, required: true },
});

const emit = defineEmits(['editar', 'excluir']);

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 4 }).format(valor);
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="px-3 py-2.5 font-semibold">Descrição</th>
                    <th class="px-3 py-2.5 font-semibold">Categoria</th>
                    <th class="px-3 py-2.5 font-semibold">Fabricante</th>
                    <th class="px-3 py-2.5 font-semibold">Cód. MP</th>
                    <th class="px-3 py-2.5 font-semibold">Largura (mm)</th>
                    <th class="px-3 py-2.5 font-semibold">R$/m²</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr v-for="mp in materiasPrimas" :key="mp.id" class="divide-x divide-gray-200 hover:bg-gray-50/60">
                    <td class="px-3 py-2.5 text-center align-middle font-medium text-gray-800">{{ mp.descMp }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ mp.categoria ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ mp.fabricante ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ mp.codMp ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ mp.largMp ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-center align-middle font-semibold text-gray-800">{{ formatBRL(mp.precoM2) }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <StatusPill :tone="mp.ativo ? 'ok' : 'neutral'">{{ mp.ativo ? 'Ativa' : 'Inativa' }}</StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <div class="flex items-center justify-center gap-1.5">
                            <button
                                type="button"
                                title="Editar"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:bg-teal/10 hover:text-teal"
                                @click="emit('editar', mp)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M4 20h4l10.5-10.5a2 2 0 0 0-4-4L4 16v4Z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                title="Excluir"
                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-red-400 hover:bg-red-50 hover:text-red-500"
                                @click="emit('excluir', mp)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5">
                                    <path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
