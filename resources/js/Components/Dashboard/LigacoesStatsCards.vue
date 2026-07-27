<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import axios from 'axios';
import DarkCard from '@/Components/DarkCard.vue';
import KpiTile from '@/Components/KpiTile.vue';

const props = defineProps({
    ligacoesStats: {
        type: Object,
        required: true,
    },
    observacoesStats: {
        type: Object,
        default: null,
    },
    role: {
        type: String,
        required: true,
    },
    visaoSupervisor: {
        type: String,
        default: null,
    },
    visaoVendedor: {
        type: String,
        default: null,
    },
});

const mesAno = computed(() => {
    const meses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const agora = new Date();
    return `${meses[agora.getMonth()]}/${agora.getFullYear()}`;
});

const podeEscrever = computed(() => ['vendedor', 'representante'].includes(props.role));

const observacoes = ref([]);
const carregando = ref(true);
const novoCnpj = ref('');
const novaMensagem = ref('');
const enviando = ref(false);

async function carregar() {
    carregando.value = true;
    const { data } = await axios.get(route('observacoes.index'), {
        params: {
            visao_supervisor: props.visaoSupervisor,
            visao_vendedor: props.visaoVendedor,
        },
    });
    observacoes.value = data;
    carregando.value = false;
}

async function enviar() {
    if (!novoCnpj.value.trim() || !novaMensagem.value.trim()) return;

    enviando.value = true;
    try {
        await axios.post(route('observacoes.store'), {
            cnpj: novoCnpj.value,
            mensagem: novaMensagem.value,
        });
        novoCnpj.value = '';
        novaMensagem.value = '';
        await carregar();
    } finally {
        enviando.value = false;
    }
}

async function fixar(observacao) {
    await axios.patch(route('observacoes.fixar', observacao.id));
    await carregar();
}

onMounted(carregar);
watch(() => [props.visaoSupervisor, props.visaoVendedor], carregar);
</script>

<template>
    <DarkCard title="Ligações e Observações" :subtitle="`${mesAno} · resumo do mês e atividade recente`">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <rect x="7" y="2" width="10" height="20" rx="2" />
                <line x1="11" y1="18" x2="13" y2="18" stroke-linecap="round" />
            </svg>
        </template>

        <div class="flex flex-wrap gap-2">
            <KpiTile :value="ligacoesStats.total" label="Total" />
            <KpiTile :value="ligacoesStats.finalizadas" label="Finalizadas" tone="ok" />
            <KpiTile :value="ligacoesStats.canceladas" label="Canceladas" tone="danger" />
            <template v-if="observacoesStats">
                <KpiTile :value="observacoesStats.hoje" label="Obs. hoje" />
                <KpiTile :value="observacoesStats.esteMes" label="Obs. no mês" />
                <KpiTile :value="observacoesStats.clientesUnicos" label="Clientes únicos" tone="info" />
            </template>
        </div>

        <div class="mt-4 border-t border-gray-100 pt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Observações recentes</p>

            <form v-if="podeEscrever" class="mt-2 flex flex-col gap-2 sm:flex-row" @submit.prevent="enviar">
                <input
                    v-model="novoCnpj"
                    type="text"
                    placeholder="CNPJ do cliente"
                    class="rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan sm:w-48"
                />
                <input
                    v-model="novaMensagem"
                    type="text"
                    placeholder="Observação..."
                    class="flex-1 rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                />
                <button
                    type="submit"
                    :disabled="enviando"
                    class="rounded-md bg-teal px-4 py-2 text-sm font-medium text-white hover:bg-teal/90 disabled:opacity-50"
                >
                    Anotar
                </button>
            </form>

            <p v-if="carregando" class="mt-3 text-sm text-gray-400">Carregando...</p>
            <p v-else-if="!observacoes.length" class="mt-3 text-sm text-gray-400">Nenhuma observação ainda.</p>

            <ul v-else class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1">
                <li
                    v-for="o in observacoes"
                    :key="o.id"
                    class="rounded border border-gray-100 p-2.5"
                >
                    <div class="flex items-center justify-between gap-2 text-xs text-gray-400">
                        <span class="truncate">{{ o.autor }} · {{ o.cnpj }}</span>
                        <button
                            v-if="o.podeEditar"
                            type="button"
                            class="shrink-0 font-medium hover:underline"
                            :class="o.fixada ? 'text-amber' : 'text-gray-400'"
                            @click="fixar(o)"
                        >
                            {{ o.fixada ? '★ Fixada' : '☆ Fixar' }}
                        </button>
                        <span v-else-if="o.fixada" class="shrink-0 font-medium text-amber">★ Fixada</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-800">{{ o.mensagem }}</p>
                </li>
            </ul>
        </div>
    </DarkCard>
</template>
