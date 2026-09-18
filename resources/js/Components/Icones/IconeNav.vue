<script setup>
/**
 * Os ícones da navegação, em um lugar só.
 *
 * ⚠️ Por que isto é componente e não `<path>` inline, se a regra do projeto diz que o
 * `<path>` de cada ícone é legitimamente inline: porque estes ícones em particular
 * aparecem em DOIS lugares que precisam concordar — a barra inferior e a gaveta mostram o
 * mesmo item do menu, e ícone diferente para o mesmo destino faz a pessoa achar que são
 * telas diferentes. O `<path>` de um ícone usado uma vez continua indo inline; ver os
 * headers dos cards e os botões `.tbl-acao`.
 *
 * ⚠️ Mesma família de traço para todos: `stroke-width` 1.75, cantos e pontas redondos,
 * nada preenchido. O único ícone preenchido do sistema é o do WhatsApp, e ele é assim
 * porque é o glifo da MARCA (ver `.tbl-acao-whats` no app.css) — não é liberdade de
 * estilo, é exceção justificada.
 *
 * ⚠️ Dimensão vem do PAI (`h-full w-full`), igual ao slot `#icon` do `DarkCard`. Ícone que
 * carrega o próprio tamanho volta a divergir entre as telas que o usam, que é justamente
 * o que `.tbl-acao svg` resolveu para os botões de tabela.
 *
 * Nome desconhecido não desenha nada e não estoura: um item de menu sem ícone é um item
 * feio, e um erro em runtime aqui derrubaria a navegação inteira.
 */
import { computed } from 'vue';

const TRACOS = {
    inicio: [
        'M3.2 10.6 12 3.4l8.8 7.2',
        'M5.8 9.5V20.6h12.4V9.5',
        'M9.9 20.6v-5.1h4.2v5.1',
    ],
    gestor: [
        'M3.4 4.2h17.2',
        'M4.8 4.2v10.6h14.4V4.2',
        'M8.4 11.6V8.2',
        'M12 11.6V6.4',
        'M15.6 11.6V9.4',
        'M12 14.8v5',
        'M9.2 19.8h5.6',
    ],
    // Visão Diretor: curva subindo sobre um eixo — tendência, não operação.
    diretor: [
        'M3.6 3.8v16.6h16.8',
        'M6.8 15.6l3.9-4.4 3.2 2.8 5.3-6.2',
        'M15.6 7.8h3.6v3.6',
    ],
    // Maiores por segmento: pizza com uma fatia destacada.
    segmentos: [
        'M11 4.2a8 8 0 1 0 8.8 8.8H11Z',
        'M14 3.2a7 7 0 0 1 6.8 6.8H14Z',
    ],
    equipe: [
        'M9.4 11.4a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2',
        'M3.6 19.6v-.7a5.8 5.8 0 0 1 11.6 0v.7',
        'M16.8 11.2a2.5 2.5 0 1 0 0-5',
        'M17.4 13.9a4.9 4.9 0 0 1 4 4.8v.9',
    ],
    // Equipe → Segmentos: grade 2×2, quem está em cada balde.
    'quadro-segmentos': [
        'M4.2 4.2h6.4v6.4H4.2Z',
        'M13.4 4.2h6.4v6.4h-6.4Z',
        'M4.2 13.4h6.4v6.4H4.2Z',
        'M13.4 13.4h6.4v6.4h-6.4Z',
    ],
    observacoes: [
        'M4.8 6.4a1.6 1.6 0 0 1 1.6-1.6h11.2a1.6 1.6 0 0 1 1.6 1.6v7a1.6 1.6 0 0 1-1.6 1.6H9.8l-4 3.2V15a1.6 1.6 0 0 1-1-1.6Z',
        'M8.4 8.8h7.2',
        'M8.4 11.6h4.4',
    ],
    metas: [
        'M12 20.8a8.8 8.8 0 1 1 0-17.6 8.8 8.8 0 0 1 0 17.6',
        'M12 16.4a4.4 4.4 0 1 1 0-8.8 4.4 4.4 0 0 1 0 8.8',
        'M12 13.2a1.2 1.2 0 1 1 0-2.4 1.2 1.2 0 0 1 0 2.4',
    ],
    carteira: [
        'M3.6 8.8h16.8v9.8a1.6 1.6 0 0 1-1.6 1.6H5.2a1.6 1.6 0 0 1-1.6-1.6V8.8Z',
        'M9 8.8V6.6a1.4 1.4 0 0 1 1.4-1.4h3.2A1.4 1.4 0 0 1 15 6.6v2.2',
        'M3.6 13.2h16.8',
    ],
    clientes: [
        'M5.2 20.4V5.4a1.2 1.2 0 0 1 1.2-1.2h7.2a1.2 1.2 0 0 1 1.2 1.2v15',
        'M14.8 10.2h3.8a1.2 1.2 0 0 1 1.2 1.2v9',
        'M3.6 20.4h16.8',
        'M8.2 8h3.6',
        'M8.2 11.6h3.6',
        'M8.2 15.2h3.6',
    ],
    leads: [
        'M9.4 11.4a3.3 3.3 0 1 0 0-6.6 3.3 3.3 0 0 0 0 6.6',
        'M3.2 19.8v-.6a6.2 6.2 0 0 1 10.6-4.4',
        'M17.6 13.4v6',
        'M14.6 16.4h6',
    ],
    pedidos: [
        'M6.4 4.4h11.2a1.4 1.4 0 0 1 1.4 1.4v13.4a1.4 1.4 0 0 1-1.4 1.4H6.4A1.4 1.4 0 0 1 5 19.2V5.8a1.4 1.4 0 0 1 1.4-1.4Z',
        'M8.6 8.6h6.8',
        'M8.6 12h6.8',
        'M8.6 15.4h4',
    ],
    'pedidos-emitidos': [
        'M6.4 4.4h11.2a1.4 1.4 0 0 1 1.4 1.4v13.4a1.4 1.4 0 0 1-1.4 1.4H6.4A1.4 1.4 0 0 1 5 19.2V5.8a1.4 1.4 0 0 1 1.4-1.4Z',
        'M8.6 8.6h6.8',
        'M8.6 13.8l2 2 3.8-4',
    ],
    orcamentos: [
        'M13.4 3.8H7.4A1.4 1.4 0 0 0 6 5.2v13.6a1.4 1.4 0 0 0 1.4 1.4h9.2a1.4 1.4 0 0 0 1.4-1.4V8.4Z',
        'M13.4 3.8v4.6H18',
        'M9 13.4h6',
        'M9 16.6h4',
    ],
    cadastros: [
        'M3.8 7.6a1.4 1.4 0 0 1 1.4-1.4h3.6l1.8 2.2h8.2a1.4 1.4 0 0 1 1.4 1.4v8.4a1.4 1.4 0 0 1-1.4 1.4H5.2a1.4 1.4 0 0 1-1.4-1.4Z',
        'M12 11.8v5.2',
        'M9.4 14.4h5.2',
    ],
    catalogo: [
        'M4.2 5.6a1.4 1.4 0 0 1 1.4-1.4h4.2A2.2 2.2 0 0 1 12 6.4v13.2a2 2 0 0 0-2-1.8H5.6a1.4 1.4 0 0 1-1.4-1.4Z',
        'M19.8 5.6a1.4 1.4 0 0 0-1.4-1.4h-4.2A2.2 2.2 0 0 0 12 6.4v13.2a2 2 0 0 1 2-1.8h4.4a1.4 1.4 0 0 0 1.4-1.4Z',
    ],
    'tabela-precos': [
        'M3.8 4.8a1 1 0 0 1 1-1h6.2a1 1 0 0 1 .72.3l8.4 8.4a1 1 0 0 1 0 1.42l-6.2 6.2a1 1 0 0 1-1.42 0L4.1 11.72a1 1 0 0 1-.3-.72V4.8Z',
        'M8.4 9.2a1.4 1.4 0 1 1 0-2.8 1.4 1.4 0 0 1 0 2.8',
    ],
    facas: [
        'M7.8 8.6a2.6 2.6 0 1 1 0-5.2 2.6 2.6 0 0 1 0 5.2',
        'M7.8 20.6a2.6 2.6 0 1 1 0-5.2 2.6 2.6 0 0 1 0 5.2',
        'M10 9.8 20 19.6',
        'M20 4.4 10 14.2',
    ],
    intranet: [
        'M3.8 10.2v3.6a1 1 0 0 0 1 1h2.4l7.4 4.4V4.8L7.2 9.2H4.8a1 1 0 0 0-1 1Z',
        'M18.2 9.2a4 4 0 0 1 0 5.6',
        'M8.2 14.8l1.3 4.6',
    ],
    perfil: [
        'M12 12.4a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        'M4.6 20.4v-.8a7.4 7.4 0 0 1 14.8 0v.8',
    ],
    downloads: [
        'M12 4.2v10.4',
        'M7.9 10.5 12 14.6l4.1-4.1',
        'M4.8 19.6h14.4',
    ],
    atualizacoes: [
        'M20.2 12a8.2 8.2 0 1 1-2.4-5.8',
        'M20.4 4.6v4.8h-4.8',
    ],
    sair: [
        'M14.4 7.6V5.8a1.6 1.6 0 0 0-1.6-1.6H6.6A1.6 1.6 0 0 0 5 5.8v12.4a1.6 1.6 0 0 0 1.6 1.6h6.2a1.6 1.6 0 0 0 1.6-1.6v-1.8',
        'M10.6 12h9',
        'M16.8 9.2 19.6 12l-2.8 2.8',
    ],
    mais: [
        'M5 7.2h14',
        'M5 12h14',
        'M5 16.8h14',
    ],
};

const props = defineProps({
    nome: { type: String, required: true },
});

/*
 * `computed`, e não uma leitura direta no setup: a gaveta e a barra reusam a mesma
 * instância quando a lista de itens muda de perfil (simulação de usuário troca o menu sem
 * desmontar o layout), e valor lido uma vez no setup deixaria o ícone antigo na tela.
 */
const tracos = computed(() => TRACOS[props.nome] ?? []);
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-full w-full"
        aria-hidden="true"
    >
        <path v-for="(d, i) in tracos" :key="i" :d="d" />
    </svg>
</template>
