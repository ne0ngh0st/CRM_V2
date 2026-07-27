<script setup>
import StatusPill from '@/Components/StatusPill.vue';

defineProps({
    etiquetas: { type: Array, required: true },
});

const emit = defineEmits(['copiar', 'enviar', 'excluir', 'detalhes']);

function tone(status) {
    return status === 'enviado' ? 'ok' : 'warn';
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[800px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="px-3 py-2.5 font-semibold">#</th>
                    <th class="px-3 py-2.5 font-semibold">Data</th>
                    <th class="px-3 py-2.5 font-semibold">Título TOTVS</th>
                    <th class="px-3 py-2.5 font-semibold">Nomenclatura</th>
                    <th class="px-3 py-2.5 font-semibold">Medidas</th>
                    <th class="px-3 py-2.5 font-semibold">Saída</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <tr v-for="item in etiquetas" :key="item.id" class="divide-x divide-gray-200 hover:bg-gray-50/60">
                    <td class="px-3 py-2.5 text-center align-middle text-gray-500">{{ item.id }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ item.data }}</td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-800">
                        <span class="mx-auto block max-w-[220px] truncate" :title="item.tituloPadronizado">{{ item.tituloPadronizado }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-700">
                        <span class="mx-auto block max-w-[140px] truncate" :title="item.nomenclatura">{{ item.nomenclatura }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600">
                        <span class="mx-auto block max-w-[120px] truncate" :title="item.medidas">{{ item.medidas || '—' }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle text-gray-600 uppercase">{{ item.saidaRolo }}</td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <StatusPill :tone="tone(item.status)">{{ item.status }}</StatusPill>
                    </td>
                    <td class="px-3 py-2.5 text-center align-middle">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" title="Detalhes" class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:border-gray-300 hover:text-gray-800" @click="emit('detalhes', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01" stroke-linecap="round"/></svg>
                            </button>
                            <button type="button" title="Copiar para formulário" class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:border-teal-300 hover:text-teal-700" @click="emit('copiar', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><rect x="8" y="8" width="12" height="12" rx="1"/><path d="M4 16V4h12" stroke-linecap="round"/></svg>
                            </button>
                            <button v-if="item.status === 'pendente'" type="button" title="Enviar p/ Cadastro" class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:border-amber-300 hover:text-amber-700" @click="emit('enviar', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path d="M4 12l16-7-7 16-2-6-7-3z" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" title="Excluir" class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 hover:border-red-300 hover:text-red-600" @click="emit('excluir', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5"><path d="M5 7h14M9 7V5h6v2M10 11v6M14 11v6M7 7l1 12h8l1-12" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
