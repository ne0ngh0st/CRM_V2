<script setup>
/**
 * Uma pessoa no quadro de segmentos: cara grande, nome curto. O quadro é
 * VISUAL — o código do vendedor fica no title, não compete com o rosto.
 *
 * Arrastar não existe no celular (mesma lição do funil): o clique abre o
 * modal de segmentos, que é o caminho principal. `draggable` só liga quando
 * dá para editar e a pessoa não é representante (aquele está travado em
 * SUPERMERCADISTA).
 */
import { computed } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import { ROTULOS_PERFIL } from '@/constants/perfis.js';

const props = defineProps({
    pessoa: { type: Object, required: true },
    podeEditar: { type: Boolean, default: false },
    arrastando: { type: Boolean, default: false },
    travada: { type: Boolean, default: false },
});

const emit = defineEmits(['clicar', 'arrastar-inicio', 'arrastar-fim']);

const primeiroNome = computed(() => {
    const partes = props.pessoa.nome.trim().split(/\s+/).filter(Boolean);
    return partes[0] || props.pessoa.nome;
});

const titulo = computed(() => {
    const perfil = ROTULOS_PERFIL[props.pessoa.perfil] || props.pessoa.perfil;
    const extra = props.pessoa.compartilhado ? ' · código compartilhado' : '';
    return `${props.pessoa.nome} · ${perfil} · ${props.pessoa.codVendedor}${extra}`;
});
</script>

<template>
    <button
        type="button"
        class="group relative flex w-[4.5rem] flex-col items-center gap-1 rounded border bg-white px-1 py-1.5 text-center transition sm:w-20"
        :class="[
            arrastando ? 'opacity-40' : 'hover:border-cyan hover:shadow-sm',
            travada ? 'border-dashed border-gray-300' : 'border-gray-200',
        ]"
        :title="titulo"
        :draggable="podeEditar && !travada"
        @click="emit('clicar', pessoa)"
        @dragstart="podeEditar && !travada && emit('arrastar-inicio', pessoa)"
        @dragend="emit('arrastar-fim')"
    >
        <Avatar :src="pessoa.fotoUrl" :nome="pessoa.nome" size="lg" />
        <span class="w-full truncate text-[0.65rem] font-medium leading-3 text-gray-700">
            {{ primeiroNome }}
        </span>
        <span
            v-if="pessoa.perfil === 'representante'"
            class="w-full truncate text-[0.55rem] uppercase leading-3 tracking-wide text-gray-400"
        >
            Rep
        </span>
    </button>
</template>
