<script setup>
/**
 * O menu completo do celular, atrás do "Mais" da `BarraInferior`.
 *
 * Deixou de ser o caminho principal em 2026-09-15 — os quatro destinos do dia a dia estão
 * na barra —, então aqui fica o que se acessa de vez em quando: gestão (Equipe, Metas,
 * Visão Gestor), Catálogo, e o menu do usuário.
 *
 * ⚠️ TELA CHEIA, não painel de 80% com o conteúdo aparecendo atrás. O menu antigo era um
 * acordeão que EMPURRAVA a página para baixo: abrir o menu movia o conteúdo, e fechar
 * devolvia a pessoa a um scroll diferente do que ela tinha deixado. Tela cheia também
 * garante que a lista inteira (até 14 itens no perfil gestor) role sem competir com o
 * scroll da página.
 *
 * ⚠️ A ESTRUTURA vem de `constants/navegacao.js`, a mesma que o nav desktop e a barra
 * inferior leem. Era escrita à mão aqui e já divergia do desktop.
 *
 * ⚠️ A trava de scroll do `<body>` usa `usePilhaDeCamadas`, a MESMA pilha do `Modal.vue`.
 * Mecanismo próprio faria as duas zerarem `body.style.overflow` sozinhas, e a que fechasse
 * por último destravaria o scroll com a outra ainda aberta — o defeito que criou a pilha.
 */
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import IconeNav from '@/Components/Icones/IconeNav.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import VisaoSupervisorToggle from '@/Components/VisaoSupervisorToggle.vue';
import { usePilhaDeCamadas } from '@/composables/usePilhaDeCamadas';
import { estaAtivo, menuPrincipal, menuUsuario } from '@/constants/navegacao';

const props = defineProps({
    show: { type: Boolean, default: false },
    /** `{ isGestor, isAssistente, isAdmin }`, montado uma vez pelo layout. */
    perfil: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const pagina = usePage();
const usuario = computed(() => pagina.props.auth.user);
const papeis = computed(() => (pagina.props.auth?.roles ?? []).join(' · '));

/* Ver a nota sobre `pagina.url` na `BarraInferior`: `route().current()` não é reativo. */
const grupos = computed(() => {
    void pagina.url;

    return menuPrincipal(props.perfil).map((grupo) => ({
        ...grupo,
        ativo: estaAtivo(grupo.ativoEm),
        itens: grupo.itens?.map((item) => ({ ...item, ativo: estaAtivo(item.ativoEm) })),
    }));
});

const itensDoUsuario = computed(() => {
    void pagina.url;

    return menuUsuario(props.perfil).map((item) => ({ ...item, ativo: estaAtivo(item.ativoEm) }));
});

const { abrir, fechar, noTopo } = usePilhaDeCamadas();

watch(
    () => props.show,
    (aberta) => (aberta ? abrir() : fechar()),
);

function aoPressionarTecla(e) {
    if (e.key === 'Escape' && props.show && noTopo()) {
        emit('close');
    }
}

/*
 * ⚠️ Fecha em QUALQUER navegação, e não num `@click` por item. Hoje o layout é remontado
 * em cada visita do Inertia, então a gaveta sumiria sozinha — mas ela sumiria só quando a
 * resposta chegasse, deixando o menu aberto por cima durante a espera. E um `@click` em
 * cada `<Link>` é a lista de itens escrita duas vezes: item novo nasceria sem o fechamento.
 */
let pararDeOuvir;

onMounted(() => {
    document.addEventListener('keydown', aoPressionarTecla);
    pararDeOuvir = router.on('start', () => emit('close'));
});

onUnmounted(() => {
    document.removeEventListener('keydown', aoPressionarTecla);
    pararDeOuvir?.();
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
    >
        <div
            v-show="show"
            class="fixed inset-0 z-50 flex flex-col bg-corp-black sm:hidden"
            role="dialog"
            aria-modal="true"
            aria-label="Menu"
        >
            <div class="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-3">
                <img src="/images/autopel-logo-white.png" alt="Autopel" class="h-8 w-auto" />

                <!-- 44px de alvo: é o botão de escapar, e errar nele prende a pessoa aqui. -->
                <button
                    type="button"
                    class="-me-2 flex h-11 w-11 items-center justify-center rounded-md text-white/70 transition hover:bg-white/10 hover:text-white"
                    aria-label="Fechar menu"
                    @click="emit('close')"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6">
                        <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>

            <!--
                O scroll é DESTE bloco, não do `<body>` (que está travado). `overscroll-contain`
                impede o gesto de rolar a gaveta até o fim de "vazar" para a página atrás.
            -->
            <div class="flex-1 overflow-y-auto overscroll-contain pb-[env(safe-area-inset-bottom)]">
                <div class="border-b border-white/10 px-4 py-4">
                    <Link :href="route('profile.edit')" class="flex items-center gap-3">
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-white/20 bg-white/10"
                        >
                            <img
                                v-if="usuario.foto_url"
                                :src="usuario.foto_url"
                                alt=""
                                class="h-full w-full object-cover"
                            />
                            <svg v-else class="h-5 w-5 text-white/70" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path
                                    d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v1.2h19.2v-1.2c0-3.2-6.4-4.8-9.6-4.8z"
                                />
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-white">
                                {{ usuario.display_name || usuario.name }}
                            </span>
                            <span class="block truncate text-xs text-white/60">{{ usuario.email }}</span>
                            <span v-if="papeis" class="mt-0.5 block truncate text-[0.65rem] uppercase tracking-wide text-cyan">
                                {{ papeis }}
                            </span>
                        </span>
                    </Link>

                    <!--
                        ⚠️ O alternador de visão do supervisor ganhou LINHA PRÓPRIA. Na versão
                        antiga ele dividia a linha do usuário com o sino, e a 320px os três
                        juntos comprimiam o nome a poucos pixels — o esmagamento documentado no
                        `PageHero`. Aqui ele também fica rotulado: fora do nav desktop, dois
                        botões soltos escritos "Equipe / Minha carteira" não dizem do que são.
                    -->
                    <div class="mt-4">
                        <p
                            v-if="$page.props.modoVisao?.disponivel"
                            class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-white/40"
                        >
                            Escopo das telas
                        </p>
                        <VisaoSupervisorToggle />
                    </div>
                </div>

                <nav class="py-2" aria-label="Menu completo">
                    <template v-for="grupo in grupos" :key="grupo.chave">
                        <!-- Grupo com filhas: rótulo de seção + itens. -->
                        <template v-if="grupo.itens?.length">
                            <div class="flex items-center gap-2 px-4 pb-1 pt-4 text-[0.65rem] font-semibold uppercase tracking-wide text-white/40">
                                <span class="h-3.5 w-3.5 shrink-0">
                                    <IconeNav :nome="grupo.icone" />
                                </span>
                                {{ grupo.rotulo }}
                            </div>
                            <ResponsiveNavLink
                                v-for="item in grupo.itens"
                                :key="item.chave"
                                :href="route(item.rota)"
                                :active="item.ativo"
                                :icone="item.icone"
                                :prefetch="item.prefetch ?? false"
                            >
                                {{ item.rotulo }}
                            </ResponsiveNavLink>
                        </template>

                        <!-- Grupo que é link direto. -->
                        <ResponsiveNavLink
                            v-else
                            :href="route(grupo.rota)"
                            :active="grupo.ativo"
                            :icone="grupo.icone"
                            :prefetch="grupo.prefetch ?? false"
                        >
                            {{ grupo.rotulo }}
                        </ResponsiveNavLink>
                    </template>
                </nav>

                <div class="mt-2 border-t border-white/10 py-2">
                    <ResponsiveNavLink
                        v-for="item in itensDoUsuario"
                        :key="item.chave"
                        :href="route(item.rota)"
                        :active="item.ativo"
                        :icone="item.icone"
                    >
                        {{ item.rotulo }}
                    </ResponsiveNavLink>

                    <!-- Nunca prefetch em logout: buscar o alvo já derrubaria a sessão. -->
                    <ResponsiveNavLink :href="route('logout')" method="post" as="button" icone="sair">
                        Sair
                    </ResponsiveNavLink>
                </div>
            </div>
        </div>
    </Transition>
</template>
