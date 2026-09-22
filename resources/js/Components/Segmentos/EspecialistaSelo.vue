<script setup>
/**
 * O especialista de um segmento: foto, estrela âmbar e nome.
 *
 * ⚠️ Componente porque aparece em TRÊS telas que precisam dizer a mesma coisa do mesmo
 * jeito (Regra de ouro nº 8): o quadro de Segmentos da Equipe, o Resumo da Visão Diretor
 * e o card "Segmentos Atendidos" do Painel. A estrela é o símbolo — é a mesma que se
 * clica no quadro para marcar a pessoa —, então ela tem que estar aqui também.
 *
 * `superficie="escuro"` é para header preto de card (DarkCard `#actions`).
 * `compacto` tira o rótulo "Especialista" e encolhe a foto, para caber numa linha de
 * tabela sem aumentar a altura dela.
 */
import Avatar from '@/Components/Avatar.vue';

defineProps({
    /** `{ id, nome, fotoUrl }` ou `null`. */
    especialista: { type: Object, default: null },
    superficie: {
        type: String,
        default: 'claro',
        validator: (v) => ['claro', 'escuro'].includes(v),
    },
    compacto: { type: Boolean, default: false },
    /** Mostra "Sem especialista" quando vazio. Na linha de tabela, o vazio fica em branco. */
    mostrarVazio: { type: Boolean, default: true },
});
</script>

<template>
    <span
        v-if="especialista"
        class="inline-flex min-w-0 items-center gap-1.5"
        :title="`Especialista do segmento: ${especialista.nome}`"
    >
        <Avatar :src="especialista.fotoUrl" :nome="especialista.nome" size="sm" :class="compacto ? 'scale-90' : ''" />
        <span class="min-w-0 leading-tight">
            <span
                v-if="! compacto"
                class="block text-[0.6rem] font-semibold uppercase tracking-wide"
                :class="superficie === 'escuro' ? 'text-gray-400' : 'text-gray-400'"
            >Especialista</span>
            <span
                class="flex min-w-0 items-center gap-1 text-xs font-semibold"
                :class="superficie === 'escuro' ? 'text-white' : 'text-gray-700'"
            >
                <svg viewBox="0 0 24 24" class="h-3 w-3 shrink-0 text-amber" fill="currentColor" aria-hidden="true">
                    <path d="M12 3.2l2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.3 2.8 1-6-4.4-4.2 6-.9Z" />
                </svg>
                <span class="truncate">{{ especialista.nome }}</span>
            </span>
        </span>
    </span>
    <span
        v-else-if="mostrarVazio"
        class="text-[0.7rem] italic"
        :class="superficie === 'escuro' ? 'text-gray-500' : 'text-gray-400'"
    >Sem especialista</span>
</template>
