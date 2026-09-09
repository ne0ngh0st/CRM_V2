<script setup>
/**
 * Atalho para o Power BI. Faixa fina, primeiro bloco da Home do gestor.
 *
 * ⚠️ SEM IFRAME desde 2026-09-06, a pedido do diretor: "retira esse painel de BI e troca
 * por um botão que leva para o navegador ver o BI". O embed ocupava 560px e, para quem não
 * estava autenticado na conta Microsoft, mostrava só a tela de login do Power BI — que foi
 * exatamente a captura que ele mandou.
 *
 * ⚠️ AZUL, e não o `bg-corp-black` de todo card (Tony, 08/09/2026: "pinta de azul pra
 * destacar"). É a única quebra deliberada do header preto do Design System, e ela só
 * funciona por ser ÚNICA: esta é a única coisa da Home que leva para FORA do CRM. Se outro
 * bloco ganhar cor de header, os dois param de destacar — a regra é "um azul na página".
 *
 * ⚠️ NÃO é um card sem corpo. A primeira versão azul reusava a estrutura do `DarkCard`
 * (header de 3,5rem, título + subtítulo longo, botão contornado) e ficou pesada justamente
 * por isso: um header do tamanho dos outros, mas sem nada embaixo, lê como card quebrado.
 * Aqui a faixa é um objeto próprio — mais baixa que um header de card, a LINHA INTEIRA é o
 * link, e o texto de apoio é curto. O que sobrava ("o seletor de visão não filtra lá")
 * virou `title`: é ressalva, não manchete.
 *
 * ⚠️ TINT de cyan (`bg-cyan/10`), não cyan CHAPADO — terceira e última correção de cor
 * (Tony, 09/09/2026: "tá muito feio, acho que é sobre as cores"). O histórico das três:
 *   1. navy chapado  → escuro demais, virava mais uma faixa preta entre o PageHero e o
 *      header do card de baixo;
 *   2. cyan chapado  → o oposto: uma barra saturada de ponta a ponta gritava MAIS que o
 *      PageHero, invertendo a hierarquia da página (o atalho parecia o assunto principal),
 *      e o texto navy sobre ela embarrava — navy e cyan são o MESMO matiz, então mesmo com
 *      5,2:1 de contraste os dois se misturam a 14px;
 *   3. tint + um só elemento saturado ← esta. A faixa fica claramente azul (o destaque que
 *      o Tony pediu continua de pé), mas a saturação mora só no botão e no filete. Fundo
 *      claro devolve ao texto o mesmo contraste que ele tem em qualquer card branco.
 *
 * ⚠️ A REGRA POR TRÁS, que vale além deste arquivo: destaque é CONTRASTE COM A VIZINHANÇA,
 * não quantidade de tinta. Numa página de cards brancos com header preto, uma faixa azul
 * CLARA destoa tanto quanto uma escura — e não compete com o título da página. Se alguém
 * um dia quiser "reforçar" isto voltando a chapar a cor, é a versão 2 de novo.
 *
 * ⚠️ Sobre o cyan da marca, TEXTO ESCURO sempre. Branco sobre #00A9CE dá 2,4:1 e some.
 * Aqui o texto é navy sobre tint claro (>12:1) — ilegibilidade deixou de ser risco, mas a
 * regra continua valendo se alguém escurecer o fundo de novo.
 */
defineProps({
    url: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <a
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        title="Abre o Power BI em nova aba. Exige conta Microsoft Pro, e o seletor de visão desta página não filtra lá."
        class="group flex items-center gap-3 overflow-hidden rounded border border-cyan/40 bg-cyan/10 px-4 py-2.5 shadow-sm transition hover:border-cyan hover:bg-cyan/20"
    >
        <!-- Filete cyan cheio: é ele que marca a faixa como "azul" mesmo com o fundo claro. -->
        <span class="-my-2.5 -ml-4 mr-1 w-1.5 self-stretch bg-cyan" />

        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-white text-cyan-dark shadow-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-4 w-4">
                <path d="M4 20V4" stroke-linecap="round" />
                <path d="M4 20h16" stroke-linecap="round" />
                <rect x="7" y="11" width="3" height="5" rx="0.5" />
                <rect x="12" y="8" width="3" height="8" rx="0.5" />
                <rect x="17" y="5" width="3" height="11" rx="0.5" />
            </svg>
        </span>

        <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold leading-tight text-navy">Dashboard BI</span>
            <span class="block overflow-hidden text-ellipsis whitespace-nowrap text-xs leading-snug text-navy/60">
                Faturamento no Power BI · abre em nova aba
            </span>
        </span>

        <!-- Único elemento saturado da faixa: é ele que o olho procura. -->
        <span
            class="inline-flex shrink-0 items-center gap-1.5 rounded bg-navy px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition group-hover:bg-cyan-dark"
        >
            Abrir
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3">
                <path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 01-1 1H6a1 1 0 01-1-1V7a1 1 0 011-1h4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </a>
</template>
