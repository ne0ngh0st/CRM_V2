<script setup>
/**
 * O painel do chat: gaveta lateral de 420px no desktop, tela cheia no celular.
 *
 * ⚠️ No desktop o painel NÃO entra na pilha de camadas: não trava o scroll da página, de
 * propósito — a pessoa pergunta sobre o que está vendo e precisa continuar rolando a
 * tela de baixo. No celular é tela cheia e entra na pilha como qualquer gaveta.
 *
 * A conversa (`IaMensagens`) e a caixa (`IaEntrada`) são as mesmas da tela cheia (/ia),
 * que mostra a mesma conversa: o botão "tela cheia" leva a pessoa para lá sem perder nada.
 */
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTelaCompacta } from '@/composables/useTelaCompacta';
import { haCamadasAbertas, usePilhaDeCamadas } from '@/composables/usePilhaDeCamadas';
import IaEntrada from '@/Components/Ia/IaEntrada.vue';
import IaListaConversas from '@/Components/Ia/IaListaConversas.vue';
import IaMensagens from '@/Components/Ia/IaMensagens.vue';
import { aberto, abrirConversa, carregarConversas, conversaId, novaConversa } from '@/composables/useAssistenteIa';

const { compacta } = useTelaCompacta();
const { abrir, fechar, noTopo } = usePilhaDeCamadas();

const texto = ref('');
const entrada = ref(null);
const vendoHistorico = ref(false);

watch(
    [aberto, compacta],
    ([estaAberto, celular]) => {
        if (estaAberto && celular) {
            abrir();
        } else {
            fechar();
        }
        if (estaAberto) {
            nextTick(() => entrada.value?.focar());
        }
    },
    { immediate: true },
);

function usarSugestao(s) {
    texto.value = s;
    entrada.value?.focar();
}

async function verHistorico() {
    vendoHistorico.value = !vendoHistorico.value;
    if (vendoHistorico.value) {
        await carregarConversas();
    }
}

async function escolherConversa(id) {
    await abrirConversa(id);
    vendoHistorico.value = false;
}

function comecarDoZero() {
    novaConversa();
    vendoHistorico.value = false;
    entrada.value?.focar();
}

function telaCheia() {
    aberto.value = false;
    router.visit(route('ia.tela', conversaId.value ? { conversa: conversaId.value } : {}));
}

function aoPressionarTecla(e) {
    if (e.key !== 'Escape' || !aberto.value) {
        return;
    }
    // No celular o painel está na pilha; no desktop, só fecha se não houver modal por cima.
    if (compacta.value ? noTopo() : !haCamadasAbertas()) {
        aberto.value = false;
    }
}

onMounted(() => document.addEventListener('keydown', aoPressionarTecla));
onUnmounted(() => document.removeEventListener('keydown', aoPressionarTecla));
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="translate-x-full opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="translate-x-full opacity-0"
    >
        <aside
            v-if="aberto"
            class="fixed inset-0 z-50 flex flex-col bg-white sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[420px] sm:border-l sm:border-gray-300 sm:shadow-2xl"
            role="dialog"
            aria-label="Assistente PALMA"
        >
            <header class="flex min-h-[3.5rem] items-center gap-3 bg-corp-black px-4 py-2 text-white">
                <span class="h-5 w-5 shrink-0 text-cyan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-full w-full" aria-hidden="true">
                        <path d="M4.6 6.2a1.8 1.8 0 0 1 1.8-1.8h11.2a1.8 1.8 0 0 1 1.8 1.8v8.2a1.8 1.8 0 0 1-1.8 1.8H11l-4.2 3.4v-3.4h-.4a1.8 1.8 0 0 1-1.8-1.8z" />
                        <path d="M12 7.6l.9 1.9 1.9.9-1.9.9-.9 1.9-.9-1.9-1.9-.9 1.9-.9z" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold leading-tight">Assistente PALMA</p>
                    <p class="truncate text-[0.7rem] text-white/60">Responde com os dados da sua carteira · beta</p>
                </div>
                <button type="button" class="ia-cab-botao" :class="{ 'bg-white/15': vendoHistorico }" title="Conversas anteriores" @click="verHistorico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 7.5V12l3 1.8" /><path d="M20.2 12a8.2 8.2 0 1 1-2.4-5.8" /><path d="M20.4 4.6v4.8h-4.8" />
                    </svg>
                </button>
                <button type="button" class="ia-cab-botao" title="Nova conversa" @click="comecarDoZero">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 5.5v13" /><path d="M5.5 12h13" />
                    </svg>
                </button>
                <button v-if="!compacta" type="button" class="ia-cab-botao" title="Abrir em tela cheia" @click="telaCheia">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M14.5 4.5h5v5" /><path d="M19.5 4.5l-6 6" /><path d="M9.5 19.5h-5v-5" /><path d="M4.5 19.5l6-6" />
                    </svg>
                </button>
                <button type="button" class="ia-cab-botao" title="Fechar (Esc)" @click="aberto = false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path d="M6.5 6.5l11 11" /><path d="M17.5 6.5l-11 11" />
                    </svg>
                </button>
            </header>

            <!-- Conversas anteriores -->
            <IaListaConversas v-if="vendoHistorico" @escolher="escolherConversa" />

            <!-- Conversa atual -->
            <template v-else>
                <IaMensagens @sugestao="usarSugestao" />
                <IaEntrada ref="entrada" v-model="texto" />
            </template>
        </aside>
    </Transition>
</template>

<style scoped>
.ia-cab-botao {
    @apply inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-white/80 transition hover:bg-white/10 hover:text-white;
}
.ia-cab-botao svg {
    @apply h-4 w-4;
}
</style>
