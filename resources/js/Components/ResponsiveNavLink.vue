<script setup>
/**
 * Item da gaveta do celular. Existe SÓ lá — o nav desktop usa `NavLink.vue`.
 *
 * ⚠️ As cores são as da Autopel desde 2026-09-15. Até então eram as do scaffold do Breeze
 * (`text-gray-600` inativo, `bg-indigo-50 text-indigo-700` ativo) dentro de um nav
 * `bg-black`: cinza-escuro sobre preto dá contraste perto de 1,5:1 e o item ativo virava
 * uma tarja índigo-clara que não existe em nenhum outro lugar do sistema. O nav desktop
 * havia sido tematizado com `!text-white/80` e este ficou para trás — era esse o "menu
 * hambúrguer meio feio" que o Tony apontou.
 *
 * ⚠️ Ativo segue a convenção que o desktop já usa: CYAN. No desktop é filete embaixo
 * (`!border-cyan`), aqui é filete à esquerda, porque a lista é vertical. Mesma cor, mesma
 * leitura; mudar de cor entre as duas telas é o que a Regra de ouro nº 5 proíbe para os
 * botões de ação e vale igual para navegação.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import IconeNav from '@/Components/Icones/IconeNav.vue';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    /** Nome no registro do `IconeNav`. Sem ícone o item continua alinhado com os vizinhos. */
    icone: {
        type: String,
        default: null,
    },
    /*
     * Prefetch do Inertia v2, repassado explicitamente em vez de contar com fallthrough
     * de atributo: declarado como prop, fica visível na assinatura do componente e não
     * depende de o `<Link>` continuar sendo o nó raiz do template.
     *
     * 'hover'  → busca ao passar o mouse (o Inertia já espera ~75ms, o que filtra o
     *            mouse passando de raspão a caminho de outro item).
     * 'click'  → busca no mousedown; compra ~100-200ms sem multiplicar carga.
     * false    → não prefetcha (o padrão, e obrigatório em ações como logout).
     */
    prefetch: {
        type: [Boolean, String],
        default: false,
    },
    /** Quanto tempo a resposta prefetchada é reaproveitada antes de buscar de novo. */
    cacheFor: {
        type: String,
        default: '30s',
    },
});

/*
 * `min-h-[2.75rem]` são os 44px de alvo de toque mínimo, e vêm da altura do container em
 * vez de `py-` maior: rótulo longo ("Observações e ligações") quebra em duas linhas em tela
 * de 320px, e com a altura vinda do padding o item ficaria de tamanhos diferentes na lista.
 */
const BASE =
    'flex w-full min-h-[2.75rem] items-center gap-3 border-l-4 py-2 pe-4 ps-3 text-start text-sm font-medium transition duration-150 ease-in-out';

const classes = computed(() =>
    props.active
        ? `${BASE} border-cyan bg-white/10 text-white`
        : `${BASE} border-transparent text-white/70 hover:bg-white/5 hover:text-white focus:bg-white/5 focus:text-white focus:outline-none`,
);
</script>

<template>
    <Link :href="href" :class="classes" :prefetch="prefetch" :cache-for="cacheFor">
        <span v-if="icone" class="h-5 w-5 shrink-0" :class="active ? 'text-cyan' : 'text-white/50'">
            <IconeNav :nome="icone" />
        </span>
        <span class="min-w-0 flex-1">
            <slot />
        </span>
    </Link>
</template>
