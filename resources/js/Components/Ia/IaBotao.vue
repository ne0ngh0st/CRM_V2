<script setup>
/**
 * Botão flutuante do assistente de IA + o painel. Montado pelo `AuthenticatedLayout` só
 * para quem passa no gate `usar-ia` (`auth.usaIa`).
 *
 * ⚠️ ESCUTA DO CANAL REFEITA A CADA MONTAGEM. O `NotificationBell` sai do canal privado
 * (`Echo.leave`) quando o layout é desmontado em cada navegação — e `leave` derruba TODOS
 * os ouvintes daquele canal, inclusive este. Por isso este componente se inscreve de novo
 * ao montar e, ao desmontar, tira só o PRÓPRIO ouvinte (`stopListening`), sem sair do canal
 * que o sino ainda usa.
 *
 * Na tela cheia (/ia) o botão e o painel somem — a conversa já está na página —, mas o
 * componente continua montado: é ele que escuta o canal por onde a resposta chega.
 */
import { computed, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import IaPainel from '@/Components/Ia/IaPainel.vue';
import { aberto, aplicarEvento } from '@/composables/useAssistenteIa';

const pagina = usePage();
const userId = computed(() => pagina.props.auth?.user?.id);
const naTelaCheia = computed(() => route().current('ia.tela'));

let canal = null;

onMounted(() => {
    if (window.Echo && userId.value) {
        canal = window.Echo.private(`App.Models.User.${userId.value}`);
        canal.listen('.ia.evento', aplicarEvento);
    }
});

onUnmounted(() => {
    canal?.stopListening('.ia.evento', aplicarEvento);
});
</script>

<template>
    <button
        v-show="!aberto && !naTelaCheia"
        type="button"
        class="fixed bottom-20 right-4 z-40 inline-flex h-12 w-12 items-center justify-center rounded-full bg-corp-black text-white shadow-lg ring-2 ring-cyan/60 transition hover:scale-105 hover:ring-cyan sm:bottom-6 sm:right-6"
        title="Assistente PALMA — tire dúvidas sobre pedidos, clientes e o sistema"
        aria-label="Abrir o assistente"
        @click="aberto = true"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6" aria-hidden="true">
            <path d="M4.6 6.2a1.8 1.8 0 0 1 1.8-1.8h11.2a1.8 1.8 0 0 1 1.8 1.8v8.2a1.8 1.8 0 0 1-1.8 1.8H11l-4.2 3.4v-3.4h-.4a1.8 1.8 0 0 1-1.8-1.8z" />
            <path d="M12 7.6l.9 1.9 1.9.9-1.9.9-.9 1.9-.9-1.9-1.9-.9 1.9-.9z" />
        </svg>
    </button>

    <IaPainel v-if="!naTelaCheia" />
</template>
