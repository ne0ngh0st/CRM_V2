<script setup>
import { computed, ref } from 'vue';

/*
 * Escolha da condição de pagamento entre as do Protheus (SE4), com busca.
 *
 * São 391 condições — um <select> nativo seria impraticável. As opções chegam do servidor
 * já ordenadas pelo USO real nos pedidos dos últimos 12 meses (CondicoesPagamento::opcoes),
 * então "28 DDL" aparece antes de "ESTAPAR VENC 15,25 DO MES SUBS" sem ninguém digitar.
 *
 * O código vai sempre ao lado da descrição: cinco descrições se repetem no Protheus
 * ("60 DDL" é 060 e 349), e só o código as distingue.
 *
 * Não existe "Outros": o Portal só aceita condição ativa no Protheus.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    opcoes: { type: Array, required: true },
    inputClass: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar condição (ex.: 28 DDL, a vista, 067)' },
});

const emit = defineEmits(['update:modelValue']);

const LIMITE = 60;

const aberto = ref(false);
const busca = ref('');

const rotulo = (o) => `${o.codigo} · ${o.descricao}`;

const selecionada = computed(() => props.opcoes.find((o) => o.codigo === props.modelValue) ?? null);

// Mesma ideia da normalização do servidor: sem espaço e sem acento, para "28/35" achar "28 / 35".
const normalizar = (t) => (t ?? '').toString().toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, '');

const filtradas = computed(() => {
    const termo = normalizar(busca.value);
    const lista = termo === ''
        ? props.opcoes
        : props.opcoes.filter((o) => o.codigo.startsWith(termo) || normalizar(o.descricao).includes(termo));
    return lista.slice(0, LIMITE);
});

function abrir() {
    busca.value = '';
    aberto.value = true;
}

function escolher(opcao) {
    emit('update:modelValue', opcao.codigo);
    aberto.value = false;
    busca.value = '';
}

function aoTeclar(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (filtradas.value.length) escolher(filtradas.value[0]);
    } else if (e.key === 'Escape') {
        aberto.value = false;
    }
}
</script>

<template>
    <div class="relative">
        <input
            type="text"
            :value="aberto ? busca : (selecionada ? rotulo(selecionada) : '')"
            :placeholder="selecionada ? rotulo(selecionada) : placeholder"
            :class="inputClass"
            autocomplete="off"
            @focus="abrir"
            @input="busca = $event.target.value"
            @blur="aberto = false"
            @keydown="aoTeclar"
        />
        <ul
            v-if="aberto"
            class="absolute left-0 right-0 z-30 mt-1 max-h-64 overflow-y-auto rounded border border-gray-300 bg-white py-1 text-left text-[0.8rem] shadow-lg"
        >
            <!-- mousedown.prevent: o clique precisa acontecer antes do blur fechar a lista. -->
            <li
                v-for="opcao in filtradas"
                :key="opcao.codigo"
                class="flex cursor-pointer items-baseline gap-2 px-2 py-1 hover:bg-cyan/10"
                :class="opcao.codigo === modelValue ? 'bg-cyan/10 font-medium' : ''"
                @mousedown.prevent="escolher(opcao)"
            >
                <span class="w-8 shrink-0 font-mono text-[0.7rem] text-gray-400">{{ opcao.codigo }}</span>
                <span class="text-gray-700">{{ opcao.descricao }}</span>
            </li>
            <li v-if="filtradas.length === 0" class="px-2 py-1 text-gray-400">Nenhuma condição encontrada.</li>
        </ul>
    </div>
</template>
