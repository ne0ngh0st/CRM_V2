<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

// Contexto de áudio em escopo de módulo (não do componente) — sobrevive a
// remontagens do sino durante a navegação da SPA, evitando criar um novo
// AudioContext a cada notificação (os navegadores limitam quantos existem).
let audioCtx = null;

function tocarSom() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        if (!audioCtx) audioCtx = new AudioCtx();
        if (audioCtx.state === 'suspended') audioCtx.resume();

        const tocarTom = (freq, inicio, duracao) => {
            const osc = audioCtx.createOscillator();
            const ganho = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            ganho.gain.setValueAtTime(0, audioCtx.currentTime + inicio);
            ganho.gain.linearRampToValueAtTime(0.15, audioCtx.currentTime + inicio + 0.02);
            ganho.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + inicio + duracao);
            osc.connect(ganho).connect(audioCtx.destination);
            osc.start(audioCtx.currentTime + inicio);
            osc.stop(audioCtx.currentTime + inicio + duracao + 0.05);
        };

        tocarTom(880, 0, 0.12);
        tocarTom(1175, 0.12, 0.18);
    } catch {
        // Autoplay de áudio bloqueado (sem interação do usuário ainda na página) — ignora silenciosamente.
    }
}

const page = usePage();
const userId = computed(() => page.props.auth.user.id);

const notificacoes = ref([]);
const contagem = ref(0);
// Incrementado só quando uma notificação chega ao vivo (nunca no carregamento
// inicial) — a mudança de valor força o Vue a remontar o svg/badge (via :key)
// e replayar a animação CSS, sem precisar de listener de animationend.
const tocou = ref(0);

const contagemExibida = computed(() => (contagem.value > 9 ? '9+' : String(contagem.value)));

async function carregar() {
    const { data } = await axios.get(route('notificacoes.index'));
    notificacoes.value = data.naoLidas;
    contagem.value = data.contagem;
}

async function abrir(notificacao) {
    notificacoes.value = notificacoes.value.filter((n) => n.id !== notificacao.id);
    contagem.value = Math.max(0, contagem.value - 1);

    axios.post(route('notificacoes.marcarLida', notificacao.id));

    if (notificacao.link) {
        router.visit(notificacao.link);
    }
}

async function marcarTodas() {
    notificacoes.value = [];
    contagem.value = 0;

    await axios.post(route('notificacoes.marcarTodas'));
}

function formatarHora(iso) {
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function aoFicarVisivel() {
    if (document.visibilityState === 'visible') {
        carregar();
    }
}

let canal = null;

onMounted(() => {
    carregar();

    if (window.Echo && userId.value) {
        canal = window.Echo.private(`App.Models.User.${userId.value}`);
        canal.listen('.notificacao.criada', (payload) => {
            notificacoes.value.unshift(payload);
            contagem.value += 1;
            tocou.value += 1;
            tocarSom();
        });
    }

    document.addEventListener('visibilitychange', aoFicarVisivel);
});

onUnmounted(() => {
    if (window.Echo && userId.value) {
        window.Echo.leave(`App.Models.User.${userId.value}`);
    }
    document.removeEventListener('visibilitychange', aoFicarVisivel);
});
</script>

<template>
    <Dropdown
        align="right"
        width="64"
        content-classes="bg-white"
    >
        <template #trigger>
            <button
                type="button"
                :key="tocou"
                class="relative inline-flex h-9 w-9 items-center justify-center rounded-full text-white/80 transition duration-150 ease-in-out hover:bg-white/10 hover:text-white focus:outline-none"
                :class="{ brilhar: tocou > 0 }"
                title="Notificações"
            >
                <svg
                    :class="['h-5 w-5', { 'animar-sino': tocou > 0 }]"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.75"
                        d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                    />
                </svg>
                <span
                    v-if="contagem > 0"
                    class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-amber-500 px-1 text-[0.6rem] font-bold leading-none text-white"
                    :class="{ 'animar-badge': tocou > 0 }"
                >
                    {{ contagemExibida }}
                </span>
            </button>
        </template>

        <template #content>
            <div class="flex items-center justify-between border-b-2 border-gray-300 bg-gray-50 px-3 py-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Notificações</span>
                <button
                    v-if="contagem > 0"
                    type="button"
                    class="text-xs font-medium text-teal-700 hover:text-teal-900"
                    @click="marcarTodas"
                >
                    Marcar todas como lidas
                </button>
            </div>

            <ul class="max-h-96 divide-y divide-gray-200 overflow-y-auto">
                <li
                    v-if="notificacoes.length === 0"
                    class="px-3 py-6 text-center text-sm text-gray-400"
                >
                    Nenhuma notificação nova.
                </li>
                <li
                    v-for="n in notificacoes"
                    :key="n.id"
                    class="cursor-pointer px-3 py-2.5 hover:bg-gray-50/60"
                    @click="abrir(n)"
                >
                    <div class="flex items-start gap-2">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-cyan" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-800">{{ n.titulo }}</p>
                            <p
                                v-if="n.mensagem"
                                class="truncate text-xs text-gray-500"
                            >
                                {{ n.mensagem }}
                            </p>
                            <p class="mt-0.5 text-[0.65rem] text-gray-400">{{ formatarHora(n.criadoEm) }}</p>
                        </div>
                    </div>
                </li>
            </ul>
        </template>
    </Dropdown>
</template>

<style scoped>
@keyframes girar-sino {
    0%, 100% { transform: rotate(0deg); }
    15% { transform: rotate(-15deg); }
    30% { transform: rotate(12deg); }
    45% { transform: rotate(-9deg); }
    60% { transform: rotate(6deg); }
    75% { transform: rotate(-3deg); }
}

.animar-sino {
    transform-origin: 50% 0%;
    animation: girar-sino 0.75s ease-in-out 2;
}

@keyframes brilho-sino {
    0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.25); }
    100% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
}

.brilhar {
    animation: brilho-sino 1.3s ease-out;
}

@keyframes pop-badge {
    0% { transform: scale(0.3); opacity: 0; }
    60% { transform: scale(1.25); opacity: 1; }
    100% { transform: scale(1); }
}

.animar-badge {
    animation: pop-badge 0.35s ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .animar-sino,
    .brilhar,
    .animar-badge {
        animation: none;
    }
}
</style>
