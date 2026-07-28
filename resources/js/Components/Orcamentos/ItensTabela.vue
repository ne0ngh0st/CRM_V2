<script setup>
import { ref } from 'vue';
import axios from 'axios';
import InputError from '@/Components/InputError.vue';
import ItemTipoModal from '@/Components/Orcamentos/ItemTipoModal.vue';
import EtiquetaCalculadora from '@/Components/Etiquetas/EtiquetaCalculadora.vue';

const props = defineProps({
    modelValue: { type: Array, required: true },
    tipoProdutoServico: { type: String, required: true },
    isAdmin: { type: Boolean, default: false },
    materiasPrimas: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const IPI_ALIQUOTA = 0.0325;
const ROTULOS_TIPO = { bobina: 'Bobina', etiqueta: 'Etiqueta', outro: 'Outro' };

const mostrarTipoModal = ref(false);
const painelAberto = ref(null);
const resultadosProduto = ref([]);
let debounceProdutoId = null;

function itens() {
    return props.modelValue;
}

function atualizar(itensNovos) {
    emit('update:modelValue', itensNovos);
}

function itemVazio(tipo) {
    return {
        tipo_item: tipo,
        cod_produto: '',
        descricao: '',
        quantidade: '1',
        valor_unitario: '',
        preco_tabela: '',
        calcula_ipi: tipo !== 'etiqueta',
        etiqueta_calc: null,
        materia_prima_id: null,
    };
}

function adicionarItem(tipo) {
    atualizar([...itens(), itemVazio(tipo)]);
}

function removerItem(idx) {
    if (itens().length <= 1) return;
    const copia = [...itens()];
    copia.splice(idx, 1);
    atualizar(copia);
}

function atualizarItem(idx, campo, valor) {
    const copia = itens().map((item, i) => (i === idx ? { ...item, [campo]: valor } : item));
    atualizar(copia);
}

function baseSemIpi(valor) {
    return valor / (1 + IPI_ALIQUOTA);
}

function participaIpi(item) {
    return props.tipoProdutoServico === 'produto' && item.tipo_item !== 'etiqueta' && !!item.calcula_ipi;
}

function valorUnitarioSemIpi(item) {
    const valor = parseFloat(item.valor_unitario) || 0;

    return participaIpi(item) ? baseSemIpi(valor) : valor;
}

function valorTotal(item, comIpi = true) {
    const qtd = parseFloat(item.quantidade) || 0;
    const valorUnit = comIpi ? parseFloat(item.valor_unitario) || 0 : valorUnitarioSemIpi(item);

    return qtd * valorUnit;
}

function descontoInfo(item) {
    const precoTabela = parseFloat(item.preco_tabela);
    if (!precoTabela || precoTabela <= 0) return null;

    const base = valorUnitarioSemIpi(item);
    if (!base) return null;

    const pct = (1 - base / precoTabela) * 100;

    if (pct > 0.01) return { tipo: 'desconto', label: `Desconto ${pct.toFixed(1)}%`, cor: 'text-amber' };
    if (pct < -0.01) return { tipo: 'margem', label: `Margem ${(-pct).toFixed(1)}%`, cor: 'text-teal' };

    return { tipo: 'na_tabela', label: 'Na tabela', cor: 'text-gray-500' };
}

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor || 0);
}

function toggleCalcIpi(idx, item) {
    if (item.tipo_item === 'etiqueta') return;
    atualizarItem(idx, 'calcula_ipi', !item.calcula_ipi);
}

function toggleCalculadora(idx) {
    painelAberto.value = painelAberto.value === `calc-${idx}` ? null : `calc-${idx}`;
}

function aplicarCalculadora(idx, resultado) {
    const copia = itens().map((item, i) => (i === idx
        ? {
            ...item,
            valor_unitario: String(resultado.valorUnitario),
            quantidade: String(resultado.quantidade),
            etiqueta_calc: resultado.etiquetaCalc,
            materia_prima_id: resultado.etiquetaCalc.materiaPrimaId || null,
        }
        : item));
    atualizar(copia);
    painelAberto.value = null;
}

function buscarProduto(idx, texto) {
    atualizarItem(idx, 'cod_produto', texto);
    clearTimeout(debounceProdutoId);

    if (texto.trim().length < 2) {
        resultadosProduto.value = [];
        painelAberto.value = null;

        return;
    }

    debounceProdutoId = setTimeout(async () => {
        const { data } = await axios.get(route('orcamentos.buscaProdutos'), { params: { q: texto.trim() } });
        resultadosProduto.value = data;
        painelAberto.value = `busca-${idx}`;
    }, 300);
}

function selecionarProduto(idx, produto) {
    const copia = itens().map((item, i) => (i === idx
        ? {
            ...item,
            cod_produto: produto.codProduto,
            descricao: produto.descricao,
            preco_tabela: produto.precoTabela !== null ? String(produto.precoTabela) : item.preco_tabela,
        }
        : item));
    atualizar(copia);
    painelAberto.value = null;
    resultadosProduto.value = [];
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-for="(item, idx) in itens()" :key="idx" class="rounded border border-gray-200 p-3">
            <div class="mb-2 flex items-center justify-between">
                <span class="rounded bg-gray-100 px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-gray-500">
                    {{ ROTULOS_TIPO[item.tipo_item] ?? item.tipo_item }}
                </span>
                <button
                    v-if="itens().length > 1"
                    type="button"
                    class="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                    @click="removerItem(idx)"
                >
                    Remover
                </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-12">
                <div class="relative sm:col-span-2">
                    <label class="text-xs font-medium text-gray-600">Código</label>
                    <input
                        :value="item.cod_produto"
                        type="text"
                        class="mt-1 block w-full rounded border-gray-300 text-sm"
                        @input="buscarProduto(idx, $event.target.value)"
                    />
                    <div
                        v-if="painelAberto === `busca-${idx}` && resultadosProduto.length"
                        class="absolute left-0 top-full z-10 mt-1 w-64 rounded border border-gray-200 bg-white shadow-lg"
                    >
                        <button
                            v-for="produto in resultadosProduto"
                            :key="produto.codProduto"
                            type="button"
                            class="block w-full border-b border-gray-100 px-2 py-1.5 text-left text-xs last:border-0 hover:bg-gray-50"
                            @click="selecionarProduto(idx, produto)"
                        >
                            <strong>{{ produto.codProduto }}</strong> — {{ produto.descricao }}
                        </button>
                    </div>
                </div>

                <div class="sm:col-span-4">
                    <label class="text-xs font-medium text-gray-600">Descrição *</label>
                    <input
                        :value="item.descricao"
                        type="text"
                        required
                        class="mt-1 block w-full rounded border-gray-300 text-sm"
                        @input="atualizarItem(idx, 'descricao', $event.target.value)"
                    />
                </div>

                <div class="sm:col-span-1">
                    <label class="text-xs font-medium text-gray-600">Qtd. *</label>
                    <input
                        :value="item.quantidade"
                        type="number"
                        min="0.01"
                        step="0.01"
                        required
                        class="mt-1 block w-full rounded border-gray-300 text-sm"
                        @input="atualizarItem(idx, 'quantidade', $event.target.value)"
                    />
                </div>

                <template v-if="tipoProdutoServico === 'produto'">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-gray-600" title="Valor unitário com IPI embutido">Vlr Unit. c/IPI *</label>
                        <input
                            :value="item.valor_unitario"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                            class="mt-1 block w-full rounded border-gray-300 text-sm"
                            @input="atualizarItem(idx, 'valor_unitario', $event.target.value)"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-gray-600">Vlr Unit. s/IPI</label>
                        <input
                            :value="formatBRL(valorUnitarioSemIpi(item))"
                            type="text"
                            disabled
                            class="mt-1 block w-full rounded border-gray-200 bg-gray-50 text-sm text-gray-500"
                        />
                    </div>
                    <div class="flex items-end gap-1 sm:col-span-1">
                        <label class="flex items-center gap-1 text-xs text-gray-600">
                            <input
                                type="checkbox"
                                :checked="item.calcula_ipi"
                                :disabled="item.tipo_item === 'etiqueta'"
                                class="rounded border-gray-300 text-cyan focus:ring-cyan"
                                @change="toggleCalcIpi(idx, item)"
                            />
                            IPI
                        </label>
                    </div>
                </template>
                <template v-else>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-gray-600">Vlr Unitário *</label>
                        <input
                            :value="item.valor_unitario"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                            class="mt-1 block w-full rounded border-gray-300 text-sm"
                            @input="atualizarItem(idx, 'valor_unitario', $event.target.value)"
                        />
                    </div>
                </template>

                <div class="sm:col-span-2">
                    <label class="text-xs font-medium text-gray-600">Preço Tabela</label>
                    <input
                        :value="item.preco_tabela"
                        type="number"
                        min="0"
                        step="0.01"
                        class="mt-1 block w-full rounded border-gray-300 text-sm"
                        @input="atualizarItem(idx, 'preco_tabela', $event.target.value)"
                    />
                </div>

                <div class="flex flex-col justify-end gap-1 text-xs sm:col-span-2">
                    <span v-if="descontoInfo(item)" :class="descontoInfo(item).cor" class="font-semibold">
                        {{ descontoInfo(item).label }}
                    </span>
                    <span class="text-gray-500">Total: <strong>{{ formatBRL(valorTotal(item, true)) }}</strong></span>
                    <span v-if="tipoProdutoServico === 'produto' && participaIpi(item)" class="text-gray-400">
                        (s/IPI: {{ formatBRL(valorTotal(item, false)) }})
                    </span>
                </div>
            </div>

            <InputError :message="errors[`itens.${idx}.descricao`]" class="mt-1" />
            <InputError :message="errors[`itens.${idx}.quantidade`]" class="mt-1" />
            <InputError :message="errors[`itens.${idx}.valor_unitario`]" class="mt-1" />

            <div v-if="item.tipo_item === 'etiqueta' && isAdmin">
                <button type="button" class="mt-2 text-xs font-medium text-teal underline" @click="toggleCalculadora(idx)">
                    {{ painelAberto === `calc-${idx}` ? 'Fechar calculadora' : 'Abrir calculadora de precificação' }}
                </button>
                <EtiquetaCalculadora
                    v-if="painelAberto === `calc-${idx}`"
                    :materias-primas="materiasPrimas"
                    :etiqueta-calc="item.etiqueta_calc"
                    @aplicar="(resultado) => aplicarCalculadora(idx, resultado)"
                />
            </div>
            <p v-else-if="item.tipo_item === 'etiqueta' && item.etiqueta_calc" class="mt-2 text-xs text-gray-400">
                Precificado via calculadora ({{ item.etiqueta_calc.materiaPrimaDesc ?? 'matéria-prima removida' }}).
            </p>
        </div>

        <button
            type="button"
            class="self-start rounded bg-teal px-3 py-1.5 text-xs font-medium text-white hover:bg-teal/90"
            @click="mostrarTipoModal = true"
        >
            + Adicionar Item
        </button>

        <ItemTipoModal :show="mostrarTipoModal" @close="mostrarTipoModal = false" @escolher="adicionarItem" />
    </div>
</template>
