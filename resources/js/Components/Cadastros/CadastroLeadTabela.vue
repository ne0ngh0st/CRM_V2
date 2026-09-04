<script setup>
import StatusPill from '@/Components/StatusPill.vue';

defineProps({
    leads: { type: Array, required: true },
});

const emit = defineEmits(['excluir']);
</script>

<template>
    <div class="tbl-wrap">
        <table class="tbl min-w-[700px]">
            <thead>
                <tr class="tbl-head-row">
                    <th class="tbl-th">#</th>
                    <th class="tbl-th">Data</th>
                    <th class="tbl-th">Razão</th>
                    <th class="tbl-th">CNPJ</th>
                    <th class="tbl-th">Telefone</th>
                    <th class="tbl-th">E-mail</th>
                    <th class="tbl-th">Status</th>
                    <th class="tbl-th">Ações</th>
                </tr>
            </thead>
            <tbody class="tbl-body">
                <tr v-for="item in leads" :key="item.id" class="tbl-row">
                    <td class="tbl-td">{{ item.id }}</td>
                    <td class="tbl-td">{{ item.data }}</td>
                    <td class="tbl-td">
                        <span class="tbl-main max-w-[180px]" :title="item.razaoSocial">{{ item.razaoSocial }}</span>
                    </td>
                    <td class="tbl-td">{{ item.cnpj || '—' }}</td>
                    <td class="tbl-td">{{ item.telefone }}</td>
                    <td class="tbl-td">
                        <span class="tbl-trunc max-w-[160px]" :title="item.email">{{ item.email }}</span>
                    </td>
                    <td class="tbl-td">
                        <StatusPill tone="ok" size="sm">{{ item.status }}</StatusPill>
                    </td>
                    <td class="tbl-td">
                        <div class="tbl-acoes">
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
