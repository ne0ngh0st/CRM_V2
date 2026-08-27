<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    rota: { type: String, required: true },
    filtros: { type: Object, default: () => ({}) },
    temFiltrosAtivos: { type: Boolean, default: false },
    /*
     * Modo assíncrono: em vez de baixar na hora, dispara um job e o usuário é avisado
     * pelo sino quando o arquivo fica pronto.
     *
     * Usado só pela Carteira, que leva ~95s e ~540MB no escopo admin — mais que o idle
     * timeout de 60s do ALB, ou seja, 504 garantido se fosse síncrono. As outras oito
     * exportações do sistema cabem no tempo e continuam baixando direto, que é melhor
     * experiência quando é possível.
     */
    assincrono: { type: Boolean, default: false },
});

const mostrarAviso = ref(false);
const mostrarPreparando = ref(false);
const enviando = ref(false);

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
    exportar();
}

function exportar() {
    mostrarAviso.value = false;

    if (!props.assincrono) {
        window.location.href = route(props.rota, queryParams.value);
        return;
    }

    enviando.value = true;
    router.post(route(props.rota), queryParams.value, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            mostrarPreparando.value = true;
        },
        onFinish: () => {
            enviando.value = false;
        },
    });
}
</script>

<template>
    <button
        type="button"
        :title="assincrono ? 'Gerar Excel (em segundo plano)' : 'Gerar Excel'"
        :disabled="enviando"
        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-600 text-gray-300 transition hover:border-emerald-400 hover:bg-emerald-400/10 hover:text-emerald-300 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-gray-600 disabled:hover:bg-transparent disabled:hover:text-gray-300"
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
                <PrimaryButton type="button" @click="exportar">Exportar</PrimaryButton>
            </div>
        </div>
    </Modal>

    <!-- Só no modo assíncrono: sem este aviso, o clique não produziria retorno visível
         nenhum e o usuário clicaria de novo achando que falhou. -->
    <Modal :show="mostrarPreparando" max-width="md" @close="mostrarPreparando = false">
        <div class="p-6">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-gray-800">Preparando sua planilha</h2>
                    <p class="mt-2 text-sm text-gray-500">
                        A carteira completa leva alguns minutos para ser gerada. Você pode continuar usando o
                        sistema — avisaremos no sino
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="inline h-4 w-4 align-text-bottom">
                            <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        assim que o arquivo estiver pronto, com o link para baixar.
                    </p>
                    <p class="mt-2 text-xs text-gray-400">O arquivo fica disponível por 7 dias.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <PrimaryButton type="button" @click="mostrarPreparando = false">Entendi</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
