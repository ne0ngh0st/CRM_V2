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
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">Descrição</th>
                    <th class="tbl-th">Categoria</th>
                    <th class="tbl-th">Fabricante</th>
                    <th class="tbl-th">Cód. MP</th>
                    <th class="tbl-th">Largura (mm)</th>
                    <th class="tbl-th">R$/m²</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="mp in materiasPrimas" :key="mp.id" class="tbl-row">
                    <td class="tbl-td font-medium text-gray-800">{{ mp.descMp }}</td>
                    <td class="tbl-td">{{ mp.categoria ?? '—' }}</td>
                    <td class="tbl-td">{{ mp.fabricante ?? '—' }}</td>
                    <td class="tbl-td">{{ mp.codMp ?? '—' }}</td>
                    <td class="tbl-td">{{ mp.largMp ?? '—' }}</td>
                    <td class="tbl-td font-medium text-gray-800">{{ formatBRL(mp.precoM2) }}</td>
                    <td class="tbl-td">
                        <StatusPill :tone="mp.ativo ? 'ok' : 'neutral'" size="sm">{{ mp.ativo ? 'Ativa' : 'Inativa' }}</StatusPill>
                    </td>
                    <td class="tbl-td">
                        <div class="tbl-acoes">
                            <button
                                type="button"
                                title="Editar"
                                class="tbl-acao tbl-acao-teal"
                                @click="emit('editar', mp)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 20h4l10.5-10.5a2 2 0 0 0-4-4L4 16v4Z" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                title="Excluir"
                                class="tbl-acao tbl-acao-danger"
                                @click="emit('excluir', mp)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
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
