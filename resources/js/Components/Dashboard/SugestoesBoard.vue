<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import DarkCard from '@/Components/DarkCard.vue';

const props = defineProps({
    role: {
        type: String,
        required: true,
    },
});

const isGestor = computed(() => ['admin', 'diretor'].includes(props.role));

const sugestoes = ref([]);
const carregando = ref(true);
const novaCategoria = ref('');
const novaMensagem = ref('');
const enviando = ref(false);
const respostas = ref({});

async function carregar() {
    carregando.value = true;
    const { data } = await axios.get(route('sugestoes.index'));
    sugestoes.value = data;
    carregando.value = false;
}

async function enviar() {
    if (!novaMensagem.value.trim()) return;

    enviando.value = true;
    try {
        await axios.post(route('sugestoes.store'), {
            categoria: novaCategoria.value || null,
            mensagem: novaMensagem.value,
        });
        novaCategoria.value = '';
        novaMensagem.value = '';
        await carregar();
    } finally {
        enviando.value = false;
    }
}

async function salvarStatus(sugestao) {
    const resposta = respostas.value[sugestao.id] ?? sugestao.respostaAdmin;
    await axios.patch(route('sugestoes.status', sugestao.id), {
        status: sugestao.status,
        resposta_admin: resposta,
    });
    await carregar();
}

async function alternarVisibilidade(sugestao) {
    await axios.patch(route('sugestoes.visibilidade', sugestao.id));
    await carregar();
}

async function excluir(sugestao) {
    await axios.delete(route('sugestoes.destroy', sugestao.id));
    await carregar();
}

onMounted(carregar);
</script>

<template>
    <DarkCard title="Sugestões e Melhorias" subtitle="Ideias e feedback da equipe">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <circle cx="12" cy="10" r="6" />
                <line x1="10" y1="17" x2="14" y2="17" stroke-linecap="round" />
                <line x1="9" y1="20" x2="15" y2="20" stroke-linecap="round" />
            </svg>
        </template>

        <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="enviar">
            <select
                v-model="novaCategoria"
                class="rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan sm:w-40"
            >
                <option value="">Categoria</option>
                <option>Sistema</option>
                <option>Relatórios</option>
                <option>Atendimento</option>
                <option>Outros</option>
            </select>
            <input
                v-model="novaMensagem"
                type="text"
                placeholder="Sua sugestão..."
                class="flex-1 rounded-md border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
            />
            <button
                type="submit"
                :disabled="enviando"
                class="rounded-md bg-teal px-4 py-2 text-sm font-medium text-white hover:bg-teal/90 disabled:opacity-50"
            >
                Enviar
            </button>
        </form>

        <p v-if="carregando" class="mt-4 text-sm text-gray-400">Carregando...</p>

        <ul v-else class="mt-4 space-y-3">
            <li
                v-for="s in sugestoes"
                :key="s.id"
                class="rounded-md border border-gray-100 p-3"
                :class="{ 'opacity-50': !s.visivel }"
            >
                <div class="flex items-center justify-between text-xs text-gray-400">
                    <span>{{ s.autor }} · {{ s.categoria || 'Outros' }}</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600">{{ s.status }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-800">{{ s.mensagem }}</p>
                <p v-if="s.respostaAdmin" class="mt-1 text-sm italic text-teal">
                    Resposta: {{ s.respostaAdmin }}
                </p>

                <div v-if="isGestor" class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2">
                    <select
                        v-model="s.status"
                        class="rounded-md border-gray-300 text-xs focus:border-cyan focus:ring-cyan"
                    >
                        <option value="pendente">Pendente</option>
                        <option value="em_analise">Em análise</option>
                        <option value="aprovada">Aprovada</option>
                        <option value="rejeitada">Rejeitada</option>
                        <option value="implementada">Implementada</option>
                    </select>
                    <input
                        v-model="respostas[s.id]"
                        type="text"
                        placeholder="Resposta..."
                        class="flex-1 min-w-[10rem] rounded-md border-gray-300 text-xs focus:border-cyan focus:ring-cyan"
                    />
                    <button
                        type="button"
                        class="text-xs font-medium text-teal hover:underline"
                        @click="salvarStatus(s)"
                    >
                        Salvar
                    </button>
                    <button
                        type="button"
                        class="text-xs font-medium text-gray-500 hover:underline"
                        @click="alternarVisibilidade(s)"
                    >
                        {{ s.visivel ? 'Ocultar' : 'Mostrar' }}
                    </button>
                    <button
                        type="button"
                        class="text-xs font-medium text-red-500 hover:underline"
                        @click="excluir(s)"
                    >
                        Excluir
                    </button>
                </div>
            </li>
        </ul>
    </DarkCard>
</template>
