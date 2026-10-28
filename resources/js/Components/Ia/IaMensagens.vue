<script setup>
/**
 * A conversa em si: boas-vindas com sugestões, as mensagens e o "pensando…".
 *
 * Usada pelo painel lateral E pela tela cheia (/ia). As duas mostram a MESMA conversa
 * (o estado mora em `useAssistenteIa`), então o desenho de uma mensagem mora aqui, uma vez.
 *
 * A resposta chega em markdown já convertido no servidor (`html`, via
 * `App\Support\Markdown::seguro`); enquanto ainda está sendo escrita, mostra o texto puro.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { avaliar, mensagens, progresso } from '@/composables/useAssistenteIa';

defineProps({
    /** Tela cheia: balões mais largos e sugestões em grade. */
    amplo: { type: Boolean, default: false },
});

const emit = defineEmits(['sugestao']);

const SUGESTOES = [
    'Qual o status do pedido…',
    'Pedidos atrasados com até 30 dias de atraso',
    'Pedidos deste mês do vendedor…',
    'Esse cliente compra todo mês?',
    'Quanto falta para a minha meta deste mês?',
    'Quem cuida do CNPJ …?',
];

const pagina = usePage();
const lista = ref(null);
const primeiroNome = computed(() => (pagina.props.auth.user?.display_name || pagina.props.auth.user?.name || '').split(' ')[0]);

/* Rola para o fim sempre que entra mensagem nova ou o texto da resposta cresce. */
watch(
    () => mensagens.value.map((m) => `${m.id}:${m.status}:${m.texto?.length ?? 0}`).join('|'),
    () => nextTick(() => lista.value?.scrollTo({ top: lista.value.scrollHeight })),
    { immediate: true },
);
</script>

<template>
    <div ref="lista" class="flex-1 overflow-y-auto bg-zinc-50" :class="amplo ? 'px-4 py-6 sm:px-8' : 'px-4 py-4'">
        <div class="space-y-3" :class="{ 'mx-auto max-w-3xl': amplo }">
            <div v-if="!mensagens.length" class="space-y-3" :class="{ 'pt-[8vh] text-center': amplo }">
                <p v-if="amplo" class="text-xl font-semibold text-gray-800">
                    Oi{{ primeiroNome ? `, ${primeiroNome}` : '' }}! Como posso ajudar?
                </p>
                <p class="text-sm text-gray-600" :class="{ 'mx-auto max-w-xl': amplo }">
                    <template v-if="!amplo">Oi{{ primeiroNome ? `, ${primeiroNome}` : '' }}! </template>
                    Pergunte sobre seus pedidos, clientes, metas ou como fazer algo no CRM. Eu consulto os dados da
                    sua carteira para responder.
                </p>
                <div class="flex flex-wrap gap-2" :class="{ 'justify-center pt-2': amplo }">
                    <button v-for="s in SUGESTOES" :key="s" type="button" class="ia-sugestao" @click="emit('sugestao', s.replace('…', '').trimEnd() + ' ')">
                        {{ s }}
                    </button>
                </div>
            </div>

            <template v-for="m in mensagens" :key="m.id">
                <div v-if="m.papel === 'usuario'" class="flex flex-col items-end">
                    <p
                        class="whitespace-pre-wrap rounded bg-navy px-3 py-2 text-sm text-white"
                        :class="[amplo ? 'max-w-[75%]' : 'max-w-[85%]', { 'opacity-40': m.status === 'cancelada' }]"
                    >{{ m.texto }}</p>
                </div>

                <div v-else class="flex justify-start">
                    <div
                        class="rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm"
                        :class="amplo ? 'max-w-full' : 'max-w-[92%]'"
                    >
                        <div v-if="m.status === 'ok'" class="ia-md" v-html="m.html" />
                        <p v-else-if="m.status === 'erro'" class="text-red-700">{{ m.erro }}</p>
                        <p v-else-if="m.status === 'cancelada'" class="text-xs italic text-gray-400">
                            Você parou esta resposta. A pergunta voltou para a caixa — ajuste e envie de novo.
                        </p>
                        <template v-else>
                            <p v-if="m.texto" class="whitespace-pre-wrap">{{ m.texto }}</p>
                            <p class="flex items-center gap-2 text-xs text-gray-400" :class="{ 'mt-1.5': m.texto }">
                                <span class="ia-digitando" aria-hidden="true"><i /><i /><i /></span>
                                {{ progresso || 'Pensando…' }}
                            </p>
                        </template>

                        <div v-if="m.status === 'ok'" class="mt-1.5 flex justify-end gap-1">
                            <button
                                type="button"
                                class="ia-voto"
                                :class="{ 'text-green-700': m.avaliacao === 1 }"
                                title="Ajudou"
                                @click="avaliar(m, 1)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M7.5 10.5v9h-3v-9z" /><path d="M7.5 10.5 11 4.2a1.9 1.9 0 0 1 3.5 1.3l-.8 3.9h4.9a1.8 1.8 0 0 1 1.8 2.1l-1.2 6.4a2 2 0 0 1-2 1.6H7.5" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="ia-voto"
                                :class="{ 'text-red-700': m.avaliacao === -1 }"
                                title="Não ajudou"
                                @click="avaliar(m, -1)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="rotate-180" aria-hidden="true">
                                    <path d="M7.5 10.5v9h-3v-9z" /><path d="M7.5 10.5 11 4.2a1.9 1.9 0 0 1 3.5 1.3l-.8 3.9h4.9a1.8 1.8 0 0 1 1.8 2.1l-1.2 6.4a2 2 0 0 1-2 1.6H7.5" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.ia-sugestao {
    @apply rounded border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-600 transition hover:border-cyan hover:text-cyan-dark;
}
.ia-voto {
    @apply inline-flex h-6 w-6 items-center justify-center rounded text-gray-400 transition hover:bg-gray-100 hover:text-gray-600;
}
.ia-voto svg {
    @apply h-3.5 w-3.5;
}

/* Markdown da resposta — sem plugin de tipografia no projeto, então o mínimo à mão. */
.ia-md :deep(p) {
    @apply my-1.5 leading-relaxed first:mt-0 last:mb-0;
}
.ia-md :deep(ul) {
    @apply my-1.5 list-disc space-y-0.5 pl-5;
}
.ia-md :deep(ol) {
    @apply my-1.5 list-decimal space-y-0.5 pl-5;
}
.ia-md :deep(strong) {
    @apply font-semibold text-gray-900;
}
.ia-md :deep(code) {
    @apply rounded bg-gray-100 px-1 text-[0.8rem];
}
.ia-md :deep(a) {
    @apply text-teal underline;
}
.ia-md :deep(h1),
.ia-md :deep(h2),
.ia-md :deep(h3) {
    @apply mb-1 mt-2 text-sm font-semibold text-gray-900;
}
.ia-md :deep(table) {
    @apply my-2 block overflow-x-auto text-xs;
}
.ia-md :deep(th),
.ia-md :deep(td) {
    @apply border border-gray-200 px-2 py-1 text-left;
}

.ia-digitando {
    @apply inline-flex gap-0.5;
}
.ia-digitando i {
    @apply h-1.5 w-1.5 rounded-full bg-gray-400;
    animation: ia-pulso 1s infinite ease-in-out;
}
.ia-digitando i:nth-child(2) {
    animation-delay: 0.15s;
}
.ia-digitando i:nth-child(3) {
    animation-delay: 0.3s;
}
@keyframes ia-pulso {
    0%,
    80%,
    100% {
        opacity: 0.25;
    }
    40% {
        opacity: 1;
    }
}
</style>
