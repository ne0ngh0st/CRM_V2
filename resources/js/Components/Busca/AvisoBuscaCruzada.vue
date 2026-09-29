<script setup>
/**
 * "1 lead também corresponde a 'kntt' → ver nos Leads" (e o contrário na tela de Leads).
 *
 * Existe porque Carteira e Leads são páginas separadas e quem procura um nome não sabe em
 * qual das duas ele está: buscava na Carteira, via "nenhum resultado" e concluía que não
 * existia. Decisão do Tony (2026-09-29): um aviso cruzado em vez de fundir as páginas.
 *
 * ⚠️ Recebe a busca APLICADA (`filtros.busca` que o servidor devolveu), nunca o que está
 * sendo digitado: assim a pergunta só sai depois que a lista da própria página já voltou.
 * Fica fora do caminho da primeira pintura, e cada termo pergunta uma vez só, em vez de a
 * cada tecla.
 *
 * ⚠️ O link leva SÓ a busca e a visão, e nenhum outro filtro: os filtros de uma página
 * não existem na outra (`status` é situação do cliente lá e etapa do lead aqui). É também
 * o que faz o número do aviso bater com o total que o clique mostra — `BuscaCruzada`
 * conta exatamente esse recorte.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

const MINIMO_CARACTERES = 3; // mesmo valor de BuscaCruzada::MINIMO_CARACTERES

const props = defineProps({
    // Onde procurar: a OUTRA página.
    alvo: { type: String, required: true, validator: (v) => ['clientes', 'leads'].includes(v) },
    busca: { type: String, default: '' },
    visaoSupervisor: { type: String, default: '' },
    visaoVendedor: { type: String, default: '' },
});

const total = ref(0);
let controle = null;

const termo = computed(() => (props.busca || '').trim());

const parametros = computed(() => {
    const p = { busca: termo.value };
    if (props.visaoSupervisor) p.visao_supervisor = props.visaoSupervisor;
    if (props.visaoVendedor) p.visao_vendedor = props.visaoVendedor;
    return p;
});

async function consultar() {
    controle?.abort();
    total.value = 0;

    if (termo.value.length < MINIMO_CARACTERES) return;

    controle = new AbortController();
    try {
        const resposta = await fetch(route('buscaCruzada', { alvo: props.alvo, ...parametros.value }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controle.signal,
        });
        if (! resposta.ok) return;
        total.value = (await resposta.json()).total ?? 0;
    } catch {
        // Abortado por um termo mais novo, ou rede fora: o aviso é uma dica, não pode
        // derrubar nada nem mostrar erro. Sem resposta, simplesmente não aparece.
    }
}

watch(parametros, consultar, { immediate: true });
onBeforeUnmount(() => controle?.abort());

const rotulo = computed(() => {
    const n = total.value;
    return props.alvo === 'leads'
        ? `${n} lead${n !== 1 ? 's' : ''}`
        : `${n} cliente${n !== 1 ? 's' : ''}`;
});

const verbo = computed(() => (total.value === 1 ? 'corresponde' : 'correspondem'));

const destino = computed(() => route(props.alvo === 'leads' ? 'leads.index' : 'carteira.index', parametros.value));
const textoLink = computed(() => (props.alvo === 'leads' ? 'Ver nos Leads →' : 'Ver na Carteira →'));
</script>

<template>
    <div
        v-if="total > 0"
        class="flex flex-wrap items-center justify-between gap-2 rounded border border-cyan/40 bg-cyan/10 px-3 py-2"
    >
        <p class="flex items-center gap-2 text-sm text-gray-700">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 shrink-0 text-cyan-dark">
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-3.5-3.5" stroke-linecap="round" />
            </svg>
            <span>
                <strong class="font-semibold">{{ rotulo }}</strong>
                também {{ verbo }} a “{{ termo }}”.
            </span>
        </p>
        <Link
            :href="destino"
            class="rounded border border-navy bg-navy px-2.5 py-1 text-xs font-medium text-white transition hover:bg-navy/90"
        >
            {{ textoLink }}
        </Link>
    </div>
</template>
