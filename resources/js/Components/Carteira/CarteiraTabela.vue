<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';
import { ROTULOS_STATUS_CARTEIRA, TONS_STATUS_CARTEIRA, ROTULOS_ADERENCIA } from '@/constants/carteira.js';

defineProps({
    clientes: { type: Array, required: true },
    podeObservar: { type: Boolean, default: false },
});

const emit = defineEmits(['motivo-inatividade', 'observacao']);

const expandido = ref(null);

function toggle(id) {
    expandido.value = expandido.value === id ? null : id;
}

function toggleOculto(cliente) {
    router.patch(route('carteira.ocultar', cliente.id), {}, { preserveScroll: true, preserveState: true });
}

function marcarContatado(cliente) {
    router.post(route('carteira.contatado', cliente.id), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm">
            <thead>
                <tr class="divide-x divide-gray-200 border-b-2 border-gray-300 bg-gray-50 text-center text-[0.65rem] uppercase tracking-wide text-gray-500">
                    <th class="w-8 px-2 py-2.5"></th>
                    <th class="px-3 py-2.5 font-semibold">Cliente</th>
                    <th class="px-3 py-2.5 font-semibold">Vendedor</th>
                    <th class="px-3 py-2.5 font-semibold">Estado</th>
                    <th class="px-3 py-2.5 font-semibold">Segmento</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-3 py-2.5 font-semibold">Aderência</th>
                    <th class="px-3 py-2.5 font-semibold">Última Compra</th>
                    <th class="px-3 py-2.5 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <template v-for="cliente in clientes" :key="cliente.id">
                    <tr class="cursor-pointer divide-x divide-gray-200 hover:bg-gray-50/60" :class="{ 'opacity-50': cliente.oculto }" @click="toggle(cliente.id)">
                        <td class="px-2 py-2.5 text-center align-middle text-gray-400">
                            <svg
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="mx-auto h-3.5 w-3.5 transition-transform"
                                :class="expandido === cliente.id ? 'rotate-90' : ''"
                            >
                                <polyline points="9,6 15,12 9,18" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <span class="mx-auto block max-w-[220px] truncate font-semibold text-gray-800" :title="cliente.razaoSocial">{{ cliente.razaoSocial }}</span>
                            <span class="block text-xs text-gray-400">{{ cliente.cnpj ?? '—' }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-700">{{ cliente.vendedorNome }}</td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.estado ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.segmento ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="TONS_STATUS_CARTEIRA[cliente.status]">{{ ROTULOS_STATUS_CARTEIRA[cliente.status] }}</StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle">
                            <StatusPill :tone="cliente.aderencia === 'dentro' ? 'ok' : 'neutral'">{{ ROTULOS_ADERENCIA[cliente.aderencia] }}</StatusPill>
                        </td>
                        <td class="px-3 py-2.5 text-center align-middle text-gray-600">{{ cliente.dataUltimaCompra ?? 'Nunca' }}</td>
                        <td class="px-3 py-2.5 text-center align-middle" @click.stop>
                            <div class="flex flex-wrap items-center justify-center gap-1.5">
                                <button
                                    type="button"
                                    :title="cliente.oculto ? 'Mostrar cliente' : 'Ocultar cliente'"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                    @click="toggleOculto(cliente)"
                                >
                                    <svg v-if="!cliente.oculto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.24 4.24M6.5 6.7C4 8.3 2 12 2 12s3.5 7 10 7c1.9 0 3.5-.6 4.8-1.4M17.4 17.4C19.7 15.8 22 12 22 12s-3.5-7-10-7c-.6 0-1.2.05-1.8.15" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    title="Marcar como contatado"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-teal hover:bg-teal/10 hover:text-teal"
                                    @click="marcarContatado(cliente)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M4 4h16v12H7l-3 3V4Z" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="m8 9 2.5 2.5L16 6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    title="Motivo de inatividade"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-amber hover:bg-amber/10 hover:text-amber"
                                    @click="emit('motivo-inatividade', cliente)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                </button>
                                <button
                                    v-if="podeObservar"
                                    type="button"
                                    title="Observações"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:border-cyan hover:bg-cyan/10 hover:text-cyan"
                                    @click="emit('observacao', cliente)"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                                        <path d="M4 4h16v12H8l-4 4V4Z" stroke-linecap="round" stroke-linejoin="round" />
                                        <line x1="8" y1="9" x2="16" y2="9" stroke-linecap="round" />
                                        <line x1="8" y1="12.5" x2="13" y2="12.5" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="expandido === cliente.id" class="bg-gray-50">
                        <td colspan="9" class="p-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Motivo de inatividade</p>
                                    <div v-if="cliente.motivoInatividade" class="mt-2 text-sm text-gray-700">
                                        <p class="font-medium">{{ cliente.motivoInatividade.motivo }}</p>
                                        <p v-if="cliente.motivoInatividade.observacao" class="mt-1 text-gray-600">{{ cliente.motivoInatividade.observacao }}</p>
                                        <p class="mt-1 text-xs text-gray-400">Registrado em {{ cliente.motivoInatividade.criadoEm }}</p>
                                    </div>
                                    <p v-else class="mt-2 text-sm text-gray-400">Nenhum motivo registrado.</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Contato</p>
                                    <p class="mt-2 text-sm text-gray-700">
                                        <span v-if="cliente.contatadoEm">Último contato marcado em {{ cliente.contatadoEm }}</span>
                                        <span v-else class="text-gray-400">Nenhum contato marcado ainda.</span>
                                    </p>
                                    <p class="mt-2 text-sm text-gray-700">Código do cliente: {{ cliente.codCliente }}</p>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
