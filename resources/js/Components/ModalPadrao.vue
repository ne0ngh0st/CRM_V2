<script setup>
import Modal from '@/Components/Modal.vue';

/*
 * Casca padrão de modal do sistema: header preto (mesma linguagem do DarkCard e do
 * PageHero), corpo branco e rodapé opcional pras ações.
 *
 * Fica aqui, e não em classes no app.css, porque isto é MARCAÇÃO repetida — header,
 * botão de fechar, estrutura — e não um punhado de classes. Componente já é fonte
 * única; jogar isso em CSS só criaria indireção sem tirar duplicação.
 *
 * `Modal.vue` continua embaixo cuidando de backdrop, ESC, trava de scroll e
 * centralização. Use `Modal.vue` direto só quando quiser algo sem header preto.
 */
defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    maxWidth: { type: String, default: 'lg' },
    // Desliga o X e o clique-fora, pra operação que não pode ser abandonada no meio.
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Modal :show="show" :max-width="maxWidth" :closeable="closeable" @close="emit('close')">
        <div class="flex items-start gap-3 bg-corp-black px-5 py-3.5">
            <span v-if="$slots.icon" class="mt-0.5 shrink-0 text-cyan">
                <slot name="icon" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-white">{{ titulo }}</h2>
                <p v-if="subtitulo" class="truncate text-xs text-gray-400" :title="subtitulo">{{ subtitulo }}</p>
            </div>
            <button
                v-if="closeable"
                type="button"
                class="shrink-0 rounded p-1 text-gray-400 transition hover:bg-white/10 hover:text-white"
                title="Fechar"
                @click="emit('close')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                    <path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" />
                </svg>
            </button>
        </div>

        <div class="px-5 py-4">
            <slot />
        </div>

        <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3">
            <slot name="footer" />
        </div>
    </Modal>
</template>
