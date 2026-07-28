<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';

const emit = defineEmits(['selecionar']);

const termo = ref('');
const resultados = ref([]);
const buscando = ref(false);
const mostrarResultados = ref(false);
let debounceId = null;

watch(termo, (valor) => {
    clearTimeout(debounceId);

    if (valor.trim().length < 2) {
        resultados.value = [];
        mostrarResultados.value = false;

        return;
    }

    debounceId = setTimeout(async () => {
        buscando.value = true;

        try {
            const { data } = await axios.get(route('orcamentos.buscaClientes'), { params: { q: valor.trim() } });
            resultados.value = data;
            mostrarResultados.value = true;
        } finally {
            buscando.value = false;
        }
    }, 300);
});

function selecionar(resultado) {
    emit('selecionar', resultado);
    termo.value = '';
    resultados.value = [];
    mostrarResultados.value = false;
}
</script>

<template>
    <div class="relative flex items-center gap-2">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 shrink-0 text-gray-400">
            <circle cx="11" cy="11" r="7" />
            <path d="m21 21-4.3-4.3" stroke-linecap="round" />
        </svg>
        <input
            v-model="termo"
            type="text"
            placeholder="Buscar cliente por CNPJ ou razão social..."
            class="w-full max-w-md rounded border-gray-300 bg-white py-1.5 text-sm focus:border-cyan focus:ring-cyan"
            @focus="mostrarResultados = resultados.length > 0"
        />
        <span v-if="buscando" class="text-xs text-gray-400">buscando...</span>

        <div
            v-if="mostrarResultados && resultados.length"
            class="absolute left-6 top-full z-10 mt-1 w-full max-w-md rounded border border-gray-200 bg-white shadow-lg"
        >
            <button
                v-for="(resultado, idx) in resultados"
                :key="idx"
                type="button"
                class="flex w-full flex-col items-start gap-0.5 border-b border-gray-100 px-3 py-2 text-left text-xs last:border-0 hover:bg-gray-50"
                @click="selecionar(resultado)"
            >
                <span class="font-semibold text-gray-800">{{ resultado.nome }}</span>
                <span class="text-gray-500">{{ resultado.cnpj || 'Sem CNPJ' }} · {{ resultado.origem === 'lead' ? 'Lead' : 'Cliente' }}</span>
            </button>
        </div>
        <button
            v-if="mostrarResultados"
            type="button"
            class="text-xs text-gray-400 underline"
            @click="mostrarResultados = false"
        >
            fechar
        </button>
    </div>
</template>
