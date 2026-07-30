<script setup>
import { computed, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    rota: { type: String, required: true },
    filtros: { type: Object, default: () => ({}) },
    temFiltrosAtivos: { type: Boolean, default: false },
});

const mostrarAviso = ref(false);

const queryParams = computed(() =>
    Object.fromEntries(
        Object.entries(props.filtros).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    ),
);

function clicar() {
    if (props.temFiltrosAtivos) {
        mostrarAviso.value = true;
        return;
    }
    baixar();
}

function baixar() {
    mostrarAviso.value = false;
    window.location.href = route(props.rota, queryParams.value);
}
</script>

<template>
    <button
        type="button"
        title="Gerar Excel"
        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-600 text-gray-300 transition hover:border-emerald-400 hover:bg-emerald-400/10 hover:text-emerald-300"
        @click="clicar"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
            <path
                d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </button>

    <Modal :show="mostrarAviso" max-width="md" @close="mostrarAviso = false">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800">Exportar com filtros ativos</h2>
            <p class="mt-2 text-sm text-gray-500">
                Você tem filtros ativos na tela — o Excel será gerado só com os dados filtrados, não com a base
                completa. Deseja continuar?
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton type="button" @click="mostrarAviso = false">Cancelar</SecondaryButton>
                <PrimaryButton type="button" @click="baixar">Exportar</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
