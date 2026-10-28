<script setup>
/**
 * A caixa de pergunta: texto, microfone, enviar/parar e "falar com o suporte".
 *
 * Usada pelo painel lateral e pela tela cheia. O texto é `v-model` porque as sugestões da
 * conversa (no componente vizinho) escrevem nele.
 *
 * O microfone só aparece onde o navegador reconhece fala (ver `useDitado`). O ditado
 * escreve na caixa e NÃO envia: a pessoa confere o que foi entendido antes.
 */
import { computed, nextTick, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { aguardandoResposta, aviso, enviando, enviar, parar } from '@/composables/useAssistenteIa';
import { useDitado } from '@/composables/useDitado';

defineProps({
    amplo: { type: Boolean, default: false },
});

const texto = defineModel({ type: String, default: '' });

const pagina = usePage();
const campo = ref(null);
const { suportado: temMicrofone, ouvindo, erro: erroMicrofone, iniciar: iniciarDitado, parar: pararDitado } = useDitado();

const ocupado = computed(() => enviando.value || aguardandoResposta());

function focar() {
    nextTick(() => campo.value?.focus());
}

async function mandar() {
    pararDitado();
    if (await enviar(texto.value, pagina.url)) {
        texto.value = '';
    }
    focar();
}

/* A pergunta volta para a caixa: quem para é porque o modelo entendeu errado. */
async function pararResposta() {
    const pergunta = await parar();
    if (pergunta && !texto.value.trim()) {
        texto.value = pergunta;
    }
    focar();
}

function alternarMicrofone() {
    if (ouvindo.value) {
        pararDitado();
        focar();
        return;
    }
    iniciarDitado(texto.value, (t) => (texto.value = t));
}

function aoDigitar(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        mandar();
    }
}

function pedirSuporte() {
    texto.value = 'Não consegui resolver. Pode abrir um chamado para o suporte?';
    mandar();
}

defineExpose({ focar });
</script>

<template>
    <footer class="border-t border-gray-200 bg-white pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2" :class="amplo ? 'px-4 sm:px-8' : 'px-3'">
        <div :class="{ 'mx-auto max-w-3xl': amplo }">
            <p v-if="aviso" class="mb-2 rounded border border-amber bg-amber/10 px-2 py-1.5 text-xs text-amber-dark">{{ aviso }}</p>
            <p v-if="erroMicrofone" class="mb-2 rounded border border-amber bg-amber/10 px-2 py-1.5 text-xs text-amber-dark">{{ erroMicrofone }}</p>
            <div class="flex items-end gap-2">
                <textarea
                    ref="campo"
                    v-model="texto"
                    :rows="amplo ? 3 : 2"
                    maxlength="2000"
                    class="max-h-40 min-h-[2.75rem] flex-1 resize-none rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                    :class="{ 'border-red-300 ring-1 ring-red-200': ouvindo }"
                    :placeholder="ouvindo ? 'Ouvindo… fale a sua pergunta' : 'Escreva sua pergunta…'"
                    :disabled="ocupado"
                    @keydown="aoDigitar"
                />
                <button
                    v-if="temMicrofone"
                    type="button"
                    class="ia-acao border"
                    :class="ouvindo ? 'animate-pulse border-red-300 bg-red-50 text-red-700' : 'border-gray-300 bg-white text-gray-600 hover:border-cyan hover:text-cyan-dark'"
                    :title="ouvindo ? 'Parar de ouvir' : 'Falar a pergunta'"
                    :aria-label="ouvindo ? 'Parar de ouvir' : 'Falar a pergunta'"
                    :aria-pressed="ouvindo"
                    :disabled="ocupado"
                    @click="alternarMicrofone"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="9" y="3.5" width="6" height="11" rx="3" /><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0" /><path d="M12 18v2.5" />
                    </svg>
                </button>
                <button
                    v-if="aguardandoResposta()"
                    type="button"
                    class="ia-acao border border-red-300 bg-white text-red-700 hover:bg-red-50"
                    title="Parar a resposta"
                    aria-label="Parar a resposta"
                    @click="pararResposta"
                >
                    <svg viewBox="0 0 24 24" fill="currentColor" class="!h-3.5 !w-3.5" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="1.5" />
                    </svg>
                </button>
                <button
                    v-else
                    type="button"
                    class="ia-acao bg-corp-black text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-40"
                    title="Enviar (Enter)"
                    :disabled="ocupado || !texto.trim()"
                    @click="mandar"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4.5 12h14" /><path d="M13 6.5 18.5 12 13 17.5" />
                    </svg>
                </button>
            </div>
            <div class="mt-1.5 flex items-center justify-between gap-2 text-[0.7rem] text-gray-400">
                <span>Pode errar. Confira números importantes na tela.</span>
                <button type="button" class="shrink-0 font-medium text-teal hover:underline disabled:opacity-40" :disabled="ocupado" @click="pedirSuporte">
                    Falar com o suporte
                </button>
            </div>
        </div>
    </footer>
</template>

<style scoped>
.ia-acao {
    @apply inline-flex h-10 w-10 shrink-0 items-center justify-center rounded transition disabled:cursor-not-allowed disabled:opacity-40;
}
.ia-acao svg {
    @apply h-4 w-4;
}
</style>
