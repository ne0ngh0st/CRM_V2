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
        Mesmo recorte do PageHero: faixa full-width, chrome fino, altura travada.
        A proporção 1140×541 no container de 1800px virava ~830px e empurrava o
        resto da Home pra fora da tela.
    -->
    <div class="overflow-hidden rounded border border-gray-300 bg-white shadow-sm">
        <div class="flex min-h-[3.5rem] flex-wrap items-center justify-between gap-3 bg-corp-black px-4 py-2.5">
            <div class="min-w-0 flex-1">
                <h3 class="flex items-center gap-2 text-sm font-semibold leading-tight text-gray-100">
                    <span class="h-4 w-4 shrink-0 text-gray-400">
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
                <p class="mt-0.5 overflow-hidden text-ellipsis whitespace-nowrap text-xs leading-snug text-gray-400">
                    Power BI · conta Microsoft Pro. O seletor de visão não filtra aqui.
                </p>
            </div>
            <a
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex shrink-0 items-center gap-1 rounded border border-gray-600 px-2 py-1 text-xs font-medium text-gray-200 transition hover:bg-white/10"
            >
                Abrir no Power BI
            </a>
        </div>
        <iframe
            :src="url"
            title="Dashboard BI"
            class="block h-[560px] w-full border-0"
            allow="fullscreen"
            allowfullscreen
        />
    </div>
</template>
