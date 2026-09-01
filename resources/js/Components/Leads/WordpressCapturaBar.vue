<script setup>
/**
 * Barra de estado da captura de leads do site, no topo da /leads.
 *
 * ⚠️ O TOKEN NUNCA APARECE AQUI. Esta página é vista por todos os vendedores;
 * o segredo do webhook mora só no .env do servidor e no campo do plugin do
 * site. O que a barra mostra é a URL base — quem configura já tem o token.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusPill from '@/Components/StatusPill.vue';

const props = defineProps({
    captura: { type: Object, required: true },
});

const copiado = ref(false);

const dono = computed(() => {
    if (!props.captura.donoCod) return 'sem dono (cadastre o form `*` em marketing_wp_formularios)';
    return props.captura.donoNome
        ? `${props.captura.donoNome} (${props.captura.donoCod})`
        : props.captura.donoCod;
});

const ultimo = computed(() => {
    if (!props.captura.ultimoRecebidoEm) return 'nenhum lead chegou ainda';
    return `último ${props.captura.ultimoRecebidoEm} · ${props.captura.hoje} hoje`;
});

// Pendente é transitório (o job resolve em até 1 min). Travada é o que exige
// gente — por isso são dois avisos, com tons diferentes.
const pendentes = computed(() => props.captura.pendentes ?? 0);
const travadas = computed(() => props.captura.travadas ?? 0);

async function copiarUrl() {
    try {
        await navigator.clipboard.writeText(props.captura.url);
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 1500);
    } catch {
        copiado.value = false;
    }
}

function enviarTeste() {
    router.post(route('leads.wordpress.teste'), {}, { preserveScroll: false });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded border border-gray-300 bg-white px-4 py-2.5 text-xs text-gray-600">
        <StatusPill :tone="captura.ligado ? 'ok' : 'danger'" size="sm" surface="light">
            {{ captura.ligado ? 'Webhook ligado' : 'Webhook desligado' }}
        </StatusPill>
        <span>{{ ultimo }}</span>
        <span class="text-gray-400">·</span>
        <span>Dono padrão: {{ dono }}</span>

        <StatusPill v-if="travadas > 0" tone="danger" size="sm" surface="light">
            {{ travadas }} captura(s) travada(s)
        </StatusPill>
        <StatusPill v-else-if="pendentes > 0" tone="warn" size="sm" surface="light">
            {{ pendentes }} na fila
        </StatusPill>

        <span class="min-w-0 truncate font-mono text-[0.65rem] text-gray-400" :title="captura.url">{{ captura.url }}</span>
        <button
            type="button"
            class="shrink-0 rounded border border-gray-300 px-2 py-1 text-[0.65rem] font-medium uppercase tracking-wide text-gray-600 hover:bg-gray-50"
            @click="copiarUrl"
        >
            {{ copiado ? 'Copiado' : 'Copiar URL' }}
        </button>

        <p v-if="!captura.ligado" class="basis-full text-[0.7rem] text-amber-700">
            Sem <code class="font-mono">WP_LEADS_WEBHOOK_SECRET</code> o site também não entra (503).
            No plugin do site, aponte para esta URL com <code class="font-mono">?token=SEGREDO</code> no fim.
        </p>
        <p v-if="travadas > 0" class="basis-full text-[0.7rem] text-red-700">
            {{ travadas }} captura(s) chegaram mas não viraram lead depois de várias tentativas.
            O envelope está guardado — nada foi perdido —, mas alguém precisa olhar
            <code class="font-mono">marketing_wp_leads_raw</code> (coluna <code class="font-mono">erro</code>).
        </p>

        <button
            v-if="captura.podeTestar"
            type="button"
            class="ml-auto shrink-0 rounded border border-navy bg-navy px-3 py-1 text-[0.65rem] font-semibold uppercase tracking-wide text-white hover:bg-navy/90"
            @click="enviarTeste"
        >
            Enviar lead de teste
        </button>
    </div>
</template>
