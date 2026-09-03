<script setup>
/**
 * Alternador "Equipe / Minha carteira" do supervisor.
 *
 * Na Autopel o supervisor também vende — exceção da casa, não prática de mercado. Este
 * botão troca o escopo em TODAS as telas de uma vez (Painel, Carteira, Leads, Pedidos,
 * Orçamentos, Metas), porque o modo é lido dentro do resolver de escopo e não passado
 * tela a tela. No legado o equivalente foi threaded à mão por ~15 arquivos e ficou
 * inconsistente entre telas.
 *
 * ⚠️ Custo por request: ZERO query. A prop vem do HandleInertiaRequests, que lê só da
 * sessão — mesmo desenho do banner de simulação.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const visao = computed(() => page.props.modoVisao ?? { disponivel: false, modo: 'equipe' });

const MODOS = [
    { chave: 'equipe', rotulo: 'Equipe' },
    { chave: 'pessoal', rotulo: 'Minha carteira' },
];

function alternar(modo) {
    if (modo === visao.value.modo) return;

    // Visita completa de propósito: o modo muda o escopo de TUDO que está na tela, então
    // recarregar só uma prop deixaria números de dois escopos convivendo na mesma página.
    router.post(route('visao.alternar'), { modo }, { preserveScroll: true });
}
</script>

<template>
    <div v-if="visao.disponivel" class="flex overflow-hidden rounded border border-white/25">
        <button
            v-for="opcao in MODOS"
            :key="opcao.chave"
            type="button"
            class="px-2 py-1 text-xs font-medium transition"
            :class="visao.modo === opcao.chave ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10'"
            :title="opcao.chave === 'pessoal' ? 'Ver só os seus clientes, como vendedor' : 'Ver os clientes da sua equipe'"
            @click="alternar(opcao.chave)"
        >
            {{ opcao.rotulo }}
        </button>
    </div>
</template>
