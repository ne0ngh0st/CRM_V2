<script setup>
defineProps({
    url: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <!--
        ⚠️ SEM IFRAME desde 2026-09-06, a pedido do diretor: "retira esse painel de BI e
        troca por um botão que leva para o navegador ver o BI". O embed ocupava 560px da
        Home e, para quem não estava autenticado na conta Microsoft, mostrava só a tela de
        login do Power BI — que foi exatamente a captura que ele mandou.

        O botão já existia no header deste componente; o que saiu foi o iframe embaixo
        dele. Restou uma faixa fina.

        ⚠️ AZUL, e não o `bg-corp-black` de todo card (Tony, 08/09/2026: "pinta de azul pra
        destacar"). É a única quebra deliberada do header preto do Design System, e ela só
        funciona porque é ÚNICA: esta faixa é a única coisa da Home que leva para fora do
        CRM, e a cor é o que a separa dos cards de dado. Se outro bloco ganhar cor de
        header, os dois param de destacar — então a regra aqui é "um azul na página".

        O tom é o `navy` oficial da marca (#0F3A69), não um azul inventado, e o ícone/borda
        usam `cyan`, que é o par dele na paleta. Sobre o navy o texto branco dá 9,7:1 e o
        cyan 4,6:1 — os dois passam.
    -->
    <div class="overflow-hidden rounded border border-navy shadow-sm">
        <div class="flex min-h-[3.5rem] flex-wrap items-center justify-between gap-3 bg-navy px-4 py-2.5">
            <div class="min-w-0 flex-1">
                <h3 class="flex items-center gap-2 text-sm font-semibold leading-tight text-white">
                    <span class="h-4 w-4 shrink-0 text-cyan">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                            <path d="M4 20V4" stroke-linecap="round" />
                            <path d="M4 20h16" stroke-linecap="round" />
                            <rect x="7" y="11" width="3" height="5" rx="0.5" />
                            <rect x="12" y="8" width="3" height="8" rx="0.5" />
                            <rect x="17" y="5" width="3" height="11" rx="0.5" />
                        </svg>
                    </span>
                    Dashboard BI
                </h3>
                <p class="mt-0.5 overflow-hidden text-ellipsis whitespace-nowrap text-xs leading-snug text-cyan">
                    Power BI · conta Microsoft Pro. Abre em nova aba; o seletor de visão não filtra lá.
                </p>
            </div>
            <!--
                ⚠️ Botão SÓLIDO (cyan sobre navy), não contornado como era sobre o preto: é
                a ação da faixa, e num bloco que existe só para levar a um lugar o alvo tem
                que ser óbvio. Texto navy sobre cyan dá 5,2:1.
            -->
            <a
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex shrink-0 items-center gap-1.5 rounded bg-cyan px-3 py-1.5 text-xs font-semibold text-navy transition hover:bg-white"
            >
                Abrir no Power BI
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3">
                    <path d="M14 5h5v5M19 5l-8 8M18 14v4a1 1 0 01-1 1H6a1 1 0 01-1-1V7a1 1 0 011-1h4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
        </div>
</div>
</template>
