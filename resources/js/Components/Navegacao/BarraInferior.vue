<script setup>
/**
 * A navegação do celular: quatro destinos fixos no pé da tela mais o botão "Mais".
 *
 * Substitui o hambúrguer como caminho PRINCIPAL (a gaveta continua existindo, atrás do
 * "Mais"). O motivo é distância de dedo: com hambúrguer, chegar na Carteira eram dois
 * toques, o primeiro no canto superior — o ponto mais longe do polegar em tela de celular.
 *
 * ⚠️ QUAIS destinos entram aqui NÃO se decide neste arquivo: vem de
 * `constants/navegacao.js` (`barraInferior()`), a mesma fonte que o nav desktop e a gaveta
 * leem. Barra com lista própria seria a terceira cópia do menu, e foi a segunda que já
 * divergiu (o link admin que existia só no desktop).
 *
 * ⚠️ `sm:hidden` — a partir de 640px a navegação volta a ser o nav do topo. Não existe
 * estado em que as duas apareçam juntas: dois lugares indicando a página atual, e um deles
 * necessariamente com convenção de "ativo" diferente, é como o usuário deixa de saber onde
 * está.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import IconeNav from '@/Components/Icones/IconeNav.vue';
import { barraInferior, estaAtivo } from '@/constants/navegacao';

const props = defineProps({
    /** `{ isGestor, isAssistente, isAdmin }`, montado uma vez pelo layout. */
    perfil: { type: Object, required: true },
    /** Marca o "Mais" como aceso enquanto a gaveta está aberta. */
    gavetaAberta: { type: Boolean, default: false },
});

defineEmits(['abrir-mais']);

const pagina = usePage();

const destinos = computed(() => barraInferior(props.perfil));

/*
 * ⚠️ Depende de `pagina.url` de propósito, mesmo sem usar o valor: `route().current()` lê
 * a URL do navegador, que não é reativa. Hoje o layout é remontado em cada visita do
 * Inertia e por isso o cálculo aconteceria de novo sozinho — mas o layout persistente está
 * na lista de coisas a fazer, e no dia em que entrar a barra congelaria no destino da
 * primeira página, sem erro nenhum aparecer.
 */
const ativos = computed(() => {
    void pagina.url;

    return destinos.value.map((destino) => estaAtivo(destino.ativoEm));
});
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-corp-black pb-[env(safe-area-inset-bottom)] sm:hidden"
        aria-label="Navegação principal"
    >
        <!--
            Grid de 5 colunas iguais, e não `flex justify-around`: com flex, "Orçamentos"
            (o rótulo mais longo) empurra os vizinhos e os alvos deixam de ter a mesma
            largura — a pessoa aprende a posição do dedo, não o rótulo.
        -->
        <div class="grid grid-cols-5">
            <!--
                ⚠️ `min-h-[3.25rem]` (52px) fica acima do mínimo de 44px de alvo de toque, e
                a altura vem do CONTAINER, não do padding: rótulo que quebre em duas linhas
                num aparelho com fonte grande empurraria a barra por cima do conteúdo.
                Por isso o rótulo também é `truncate`.
            -->
            <Link
                v-for="(destino, i) in destinos"
                :key="destino.chave"
                :href="route(destino.rota)"
                prefetch="hover"
                class="flex min-h-[3.25rem] flex-col items-center justify-center gap-0.5 border-t-2 px-1 pb-1 pt-1.5 transition"
                :class="ativos[i]
                    ? 'border-cyan text-cyan'
                    : 'border-transparent text-white/60 active:bg-white/10'"
                :aria-current="ativos[i] ? 'page' : undefined"
            >
                <span class="h-5 w-5 shrink-0">
                    <IconeNav :nome="destino.icone" />
                </span>
                <span class="w-full truncate text-center text-[0.65rem] font-medium leading-none">
                    {{ destino.rotulo }}
                </span>
            </Link>

            <!--
                O "Mais" é o 5º alvo e NÃO é um destino: é o controle da gaveta. Fica por
                último porque é o canto mais fácil para o polegar da mão direita, e é o
                único que a pessoa vai tocar sem saber para onde vai.
            -->
            <button
                type="button"
                class="flex min-h-[3.25rem] flex-col items-center justify-center gap-0.5 border-t-2 px-1 pb-1 pt-1.5 transition"
                :class="gavetaAberta
                    ? 'border-cyan text-cyan'
                    : 'border-transparent text-white/60 active:bg-white/10'"
                :aria-expanded="gavetaAberta"
                @click="$emit('abrir-mais')"
            >
                <span class="h-5 w-5 shrink-0">
                    <IconeNav nome="mais" />
                </span>
                <span class="w-full truncate text-center text-[0.65rem] font-medium leading-none">
                    Mais
                </span>
            </button>
        </div>
    </nav>
</template>
