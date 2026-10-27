<script setup>
/**
 * O painel do chat: gaveta lateral de 420px no desktop, tela cheia no celular.
 *
 * ⚠️ No desktop o painel NÃO entra na pilha de camadas: não trava o scroll da página, de
 * propósito — a pessoa pergunta sobre o que está vendo e precisa continuar rolando a
 * tela de baixo. No celular é tela cheia e entra na pilha como qualquer gaveta.
 *
 * A resposta chega em markdown já convertido no servidor (`html`, via
 * `App\Support\Markdown::seguro`); enquanto ainda está sendo escrita, mostra o texto puro.
 */
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useTelaCompacta } from '@/composables/useTelaCompacta';
import { haCamadasAbertas, usePilhaDeCamadas } from '@/composables/usePilhaDeCamadas';
import {
    aberto,
    abrirConversa,
    aguardandoResposta,
    aviso,
    avaliar,
    carregarConversas,
    conversaId,
    conversas,
    enviando,
    enviar,
    mensagens,
    novaConversa,
    parar,
    progresso,
} from '@/composables/useAssistenteIa';

const SUGESTOES = [
    'Qual o status do pedido…',
    'Por que o cliente … está inativo?',
    'Quanto falta para a minha meta deste mês?',
    'Quem cuida do CNPJ …?',
    'Os dados de hoje já foram atualizados?',
];

const pagina = usePage();
const { compacta } = useTelaCompacta();
const { abrir, fechar, noTopo } = usePilhaDeCamadas();

const texto = ref('');
const campo = ref(null);
const lista = ref(null);
const vendoHistorico = ref(false);

const ocupado = computed(() => enviando.value || aguardandoResposta());
const primeiroNome = computed(() => (pagina.props.auth.user?.display_name || pagina.props.auth.user?.name || '').split(' ')[0]);

watch(
    [aberto, compacta],
    ([estaAberto, celular]) => {
        if (estaAberto && celular) {
            abrir();
        } else {
            fechar();
        }
        if (estaAberto) {
            nextTick(() => campo.value?.focus());
        }
    },
    { immediate: true },
);

/* Rola para o fim sempre que entra mensagem nova ou o texto da resposta cresce. */
watch(
    () => mensagens.value.map((m) => `${m.id}:${m.status}:${m.texto?.length ?? 0}`).join('|'),
    () => nextTick(() => lista.value?.scrollTo({ top: lista.value.scrollHeight })),
);

async function mandar() {
    const atual = texto.value;
    if (await enviar(atual, pagina.url)) {
        texto.value = '';
    }
    nextTick(() => campo.value?.focus());
}

/* A pergunta volta para a caixa: quem para é porque o modelo entendeu errado. */
async function pararResposta() {
    const pergunta = await parar();
    if (pergunta && !texto.value.trim()) {
        texto.value = pergunta;
    }
    nextTick(() => campo.value?.focus());
}

function aoDigitar(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        mandar();
    }
}

function usarSugestao(s) {
    texto.value = s.replace('…', '').trimEnd() + ' ';
    nextTick(() => campo.value?.focus());
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
    nextTick(() => campo.value?.focus());
}

function pedirSuporte() {
    texto.value = 'Não consegui resolver. Pode abrir um chamado para o suporte?';
    mandar();
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
                <button type="button" class="ia-cab-botao" title="Fechar (Esc)" @click="aberto = false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path d="M6.5 6.5l11 11" /><path d="M17.5 6.5l-11 11" />
                    </svg>
                </button>
            </header>

            <!-- Conversas anteriores -->
            <div v-if="vendoHistorico" class="flex-1 overflow-y-auto">
                <p class="px-4 pb-1 pt-3 text-xs font-semibold uppercase text-gray-400">Conversas anteriores</p>
                <p v-if="conversas === null" class="px-4 py-3 text-sm text-gray-500">Carregando…</p>
                <p v-else-if="!conversas.length" class="px-4 py-3 text-sm text-gray-500">Nenhuma conversa ainda.</p>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="c in conversas" :key="c.id">
                        <button
                            type="button"
                            class="flex w-full items-baseline gap-3 px-4 py-2.5 text-left text-sm hover:bg-gray-50"
                            :class="{ 'bg-cyan/10': c.id === conversaId }"
                            @click="escolherConversa(c.id)"
                        >
                            <span class="min-w-0 flex-1 truncate text-gray-700">{{ c.titulo }}</span>
                            <span class="shrink-0 text-[0.7rem] text-gray-400">{{ c.quando }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- Conversa atual -->
            <div v-else ref="lista" class="flex-1 space-y-3 overflow-y-auto bg-zinc-50 px-4 py-4">
                <div v-if="!mensagens.length" class="space-y-3">
                    <p class="text-sm text-gray-700">
                        Oi{{ primeiroNome ? `, ${primeiroNome}` : '' }}! Pergunte sobre seus pedidos, clientes, metas ou
                        como fazer algo no CRM. Eu consulto os dados da sua carteira para responder.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in SUGESTOES" :key="s" type="button" class="ia-sugestao" @click="usarSugestao(s)">
                            {{ s }}
                        </button>
                    </div>
                </div>

                <template v-for="m in mensagens" :key="m.id">
                    <div v-if="m.papel === 'usuario'" class="flex flex-col items-end">
                        <p
                            class="max-w-[85%] whitespace-pre-wrap rounded bg-navy px-3 py-2 text-sm text-white"
                            :class="{ 'opacity-40': m.status === 'cancelada' }"
                        >{{ m.texto }}</p>
                    </div>

                    <div v-else class="flex justify-start">
                        <div class="max-w-[92%] rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm">
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

            <footer v-if="!vendoHistorico" class="border-t border-gray-200 bg-white px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2">
                <p v-if="aviso" class="mb-2 rounded border border-amber bg-amber/10 px-2 py-1.5 text-xs text-amber-dark">{{ aviso }}</p>
                <div class="flex items-end gap-2">
                    <textarea
                        ref="campo"
                        v-model="texto"
                        rows="2"
                        maxlength="2000"
                        class="max-h-40 min-h-[2.75rem] flex-1 resize-none rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                        placeholder="Escreva sua pergunta…"
                        :disabled="ocupado"
                        @keydown="aoDigitar"
                    />
                    <button
                        v-if="aguardandoResposta()"
                        type="button"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded border border-red-300 bg-white text-red-700 transition hover:bg-red-50"
                        title="Parar a resposta"
                        aria-label="Parar a resposta"
                        @click="pararResposta"
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                            <rect x="6" y="6" width="12" height="12" rx="1.5" />
                        </svg>
                    </button>
                    <button
                        v-else
                        type="button"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded bg-corp-black text-white transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-40"
                        title="Enviar (Enter)"
                        :disabled="ocupado || !texto.trim()"
                        @click="mandar"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="h-4 w-4" aria-hidden="true">
                            <path d="M4.5 12h14" /><path d="M13 6.5 18.5 12 13 17.5" />
                        </svg>
                    </button>
                </div>
                <div class="mt-1.5 flex items-center justify-between text-[0.7rem] text-gray-400">
                    <span>Pode errar. Confira números importantes na tela.</span>
                    <button type="button" class="font-medium text-teal hover:underline disabled:opacity-40" :disabled="ocupado" @click="pedirSuporte">
                        Falar com o suporte
                    </button>
                </div>
            </footer>
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
