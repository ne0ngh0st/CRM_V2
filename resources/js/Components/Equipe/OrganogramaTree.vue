<script setup>
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

defineProps({
    nos: { type: Array, default: () => [] },
    nivel: { type: Number, default: 0 },
});
</script>

<template>
    <ul class="space-y-1.5" :class="nivel > 0 ? 'ms-5 border-l border-gray-200 ps-4 pt-1.5' : ''">
        <li v-for="no in nos" :key="no.id">
            <div class="flex flex-wrap items-center gap-2 rounded border border-gray-200 bg-gray-50 px-2.5 py-1.5">
                <span class="text-sm font-semibold text-gray-800">{{ no.nome }}</span>
                <span class="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-gray-500">
                    {{ ROTULOS_PERFIL[no.perfil] || no.perfil }}
                </span>
                <span v-if="no.filhos.length" class="text-xs text-gray-400">{{ no.filhos.length }} na equipe</span>
            </div>
            <OrganogramaTree v-if="no.filhos.length" :nos="no.filhos" :nivel="nivel + 1" />
        </li>
    </ul>
</template>
