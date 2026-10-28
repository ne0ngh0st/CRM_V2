<script setup>
/**
 * O assistente em tela cheia (/ia): conversas na lateral, a conversa no centro.
 *
 * ⚠️ É a MESMA conversa do painel lateral — o estado mora em `useAssistenteIa`, no escopo
 * do módulo. Abrir esta tela pelo botão do painel continua de onde parou; voltar para
 * outra página e abrir o painel também. Por isso a página não carrega dado nenhum pelo
 * Inertia: lista e mensagens vêm dos mesmos endpoints JSON do painel.
 *
 * O botão flutuante do assistente some aqui (ver `IaBotao`), mas continua montado: é ele
 * que escuta o canal do Reverb por onde a resposta chega.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import IaEntrada from '@/Components/Ia/IaEntrada.vue';
import IaListaConversas from '@/Components/Ia/IaListaConversas.vue';
import IaMensagens from '@/Components/Ia/IaMensagens.vue';
import { aberto, abrirConversa, carregarConversas, conversaId, conversas, novaConversa } from '@/composables/useAssistenteIa';

const props = defineProps({
    conversaInicial: { type: Number, default: null },
});

const texto = ref('');
const entrada = ref(null);
/* No celular a lista de conversas é uma gaveta; no desktop fica sempre aberta. */
const listaAberta = ref(false);

const titulo = computed(() => {
    if (!conversaId.value) {
        return 'Nova conversa';
    }
    return conversas.value?.find((c) => c.id === conversaId.value)?.titulo ?? 'Conversa';
});

async function escolher(id) {
    listaAberta.value = false;
    if (id !== conversaId.value) {
        await abrirConversa(id);
    }
    entrada.value?.focar();
}

function nova() {
    listaAberta.value = false;
    novaConversa();
    texto.value = '';
    entrada.value?.focar();
}

function usarSugestao(s) {
    texto.value = s;
    entrada.value?.focar();
}

onMounted(async () => {
    aberto.value = false;
    if (props.conversaInicial && props.conversaInicial !== conversaId.value) {
        await abrirConversa(props.conversaInicial);
    }
    await carregarConversas(200);
    nextTick(() => entrada.value?.focar());
});

/*
 * Conversa nova ganha título no servidor só na primeira pergunta: quando o id muda para
 * um que a lateral ainda não tem, recarrega a lista para ela aparecer lá.
 */
watch(conversaId, (id) => {
    if (id && conversas.value && !conversas.value.some((c) => c.id === id)) {
        carregarConversas(200);
    }
});
</script>

<template>
    <Head title="Assistente" />

    <AuthenticatedLayout>
        <!--
            Altura = tela menos a navbar (4rem) e, no celular, menos a folga da barra
            inferior que o layout já reserva no <main> (5rem).
        -->
        <div class="relative flex h-[calc(100dvh-9rem)] overflow-hidden border-t border-gray-200 bg-white sm:h-[calc(100dvh-4rem)]">
            <!-- Lateral: conversas -->
            <div
                v-if="listaAberta"
                class="absolute inset-0 z-20 bg-corp-black/40 sm:hidden"
                aria-hidden="true"
                @click="listaAberta = false"
            />
            <aside
                class="absolute inset-y-0 left-0 z-30 flex w-72 flex-col border-r border-gray-200 bg-gray-50 transition-transform sm:static sm:translate-x-0"
                :class="listaAberta ? 'translate-x-0 shadow-xl' : '-translate-x-full'"
                aria-label="Conversas"
            >
                <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-3">
                    <button
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded bg-corp-black px-3 py-2 text-sm font-medium text-white transition hover:bg-black"
                        @click="nova"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" class="h-4 w-4" aria-hidden="true">
                            <path d="M12 5.5v13" /><path d="M5.5 12h13" />
                        </svg>
                        Nova conversa
                    </button>
                </div>
                <IaListaConversas @escolher="escolher" />
            </aside>

            <!-- Conversa -->
            <section class="flex min-w-0 flex-1 flex-col">
                <header class="flex min-h-[3.5rem] items-center gap-3 bg-corp-black px-4 py-2 text-white">
                    <button
                        type="button"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-white/80 hover:bg-white/10 sm:hidden"
                        title="Conversas"
                        aria-label="Abrir a lista de conversas"
                        @click="listaAberta = true"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" class="h-4 w-4" aria-hidden="true">
                            <path d="M4.5 7h15" /><path d="M4.5 12h15" /><path d="M4.5 17h15" />
                        </svg>
                    </button>
                    <span class="h-5 w-5 shrink-0 text-cyan">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-full w-full" aria-hidden="true">
                            <path d="M4.6 6.2a1.8 1.8 0 0 1 1.8-1.8h11.2a1.8 1.8 0 0 1 1.8 1.8v8.2a1.8 1.8 0 0 1-1.8 1.8H11l-4.2 3.4v-3.4h-.4a1.8 1.8 0 0 1-1.8-1.8z" />
                            <path d="M12 7.6l.9 1.9 1.9.9-1.9.9-.9 1.9-.9-1.9-1.9-.9 1.9-.9z" />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold leading-tight">{{ titulo }}</p>
                        <p class="truncate text-[0.7rem] text-white/60">Assistente PALMA · responde com os dados da sua carteira · beta</p>
                    </div>
                </header>

                <IaMensagens amplo @sugestao="usarSugestao" />
                <IaEntrada ref="entrada" v-model="texto" amplo />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
