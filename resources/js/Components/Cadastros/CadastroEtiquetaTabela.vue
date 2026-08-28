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
    <div class="tbl-wrap">
        <table class="tbl min-w-[800px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">#</th>
                    <th class="tbl-th">Data</th>
                    <th class="tbl-th">Título TOTVS</th>
                    <th class="tbl-th">Nomenclatura</th>
                    <th class="tbl-th">Medidas</th>
                    <th class="tbl-th">Saída</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="item in etiquetas" :key="item.id" class="tbl-row">
                    <td class="tbl-td">{{ item.id }}</td>
                    <td class="tbl-td">{{ item.data }}</td>
                    <td class="tbl-td">
                        <span class="tbl-main mx-auto max-w-[220px]" :title="item.tituloPadronizado">{{ item.tituloPadronizado }}</span>
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[140px]" :title="item.nomenclatura">{{ item.nomenclatura }}</span>
                    </td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[120px]" :title="item.medidas">{{ item.medidas || '—' }}</span>
                    </td>
                    <td class="tbl-td uppercase">{{ item.saidaRolo }}</td>
                    <td class="tbl-td">
                        <StatusPill :tone="tone(item.status)" size="sm">{{ item.status }}</StatusPill>
                    </td>
                    <td class="tbl-td">
                        <div class="tbl-acoes">
                            <button type="button" title="Detalhes" class="tbl-acao tbl-acao-neutro" @click="emit('detalhes', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01" stroke-linecap="round"/></svg>
                            </button>
                            <button type="button" title="Copiar para formulário" class="tbl-acao tbl-acao-teal" @click="emit('copiar', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="8" y="8" width="12" height="12" rx="1"/><path d="M4 16V4h12" stroke-linecap="round"/></svg>
                            </button>
                            <button v-if="item.status === 'pendente'" type="button" title="Enviar p/ Cadastro" class="tbl-acao tbl-acao-amber" @click="emit('enviar', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 12l16-7-7 16-2-6-7-3z" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" title="Excluir" class="tbl-acao tbl-acao-danger" @click="emit('excluir', item)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 7h14M9 7V5h6v2M10 11v6M14 11v6M7 7l1 12h8l1-12" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
