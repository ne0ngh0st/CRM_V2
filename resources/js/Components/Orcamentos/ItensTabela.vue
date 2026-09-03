<script setup>
import {
    calcularItem,
    participaIpi as participaIpiCalc,
    valorUnitarioSemIpi as valorUnitarioSemIpiCalc,
} from '@/utils/orcamento';
/**
 * Tabela de itens da folha de orçamento.
 *
 * ⚠️ A linha principal reproduz coluna por coluna a tabela de itens do PDF
 * (resources/views/orcamentos/pdf.blade.php) — mesma ordem, mesmos rótulos,
 * mesmo alinhamento. É o que sustenta a promessa de que a tela é o documento.
 *
 * O que o cliente NÃO vê (preço de tabela, desconto apurado, chave de IPI,
 * calculadora de etiqueta) fica numa linha de apoio abaixo, marcada como tal —
 * em vez de virar coluna e descaracterizar o documento.
 */
import { ref } from 'vue';
import axios from 'axios';
import InputError from '@/Components/InputError.vue';
import ItemTipoModal from '@/Components/Orcamentos/ItemTipoModal.vue';
import EtiquetaCalculadora from '@/Components/Etiquetas/EtiquetaCalculadora.vue';
import { formatBRL, formatNumero } from '@/utils/formato.js';

const props = defineProps({
    modelValue: { type: Array, required: true },
    tipoProdutoServico: { type: String, required: true },
    isAdmin: { type: Boolean, default: false },
    materiasPrimas: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

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

// A matemática de IPI mora em utils/orcamento.js (espelho do OrcamentoCalculoService do
// PHP). Não reimplementar aqui — ver Regra de ouro nº 8.
function participaIpi(item) {
    return participaIpiCalc(item, props.tipoProdutoServico);
}

function valorUnitarioSemIpi(item) {
    return valorUnitarioSemIpiCalc(item, props.tipoProdutoServico);
}

function valorTotal(item, comIpi = true) {
    const calculado = calcularItem(item, props.tipoProdutoServico);

    return comIpi ? calculado.valorTotalComIpi : calculado.valorTotalSemIpi;
}

function descontoInfo(item) {
    const precoTabela = parseFloat(item.preco_tabela);
    if (!precoTabela || precoTabela <= 0) return null;

    const base = valorUnitarioSemIpi(item);
    if (!base) return null;

    const pct = (1 - base / precoTabela) * 100;

    if (pct > 0.01) return { label: `Desconto ${pct.toFixed(1)}%`, cor: 'text-amber-dark' };
    if (pct < -0.01) return { label: `Margem ${(-pct).toFixed(1)}%`, cor: 'text-teal' };

    return { label: 'Na tabela', cor: 'text-gray-500' };
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

function numeroLinha(idx) {
    return String(idx + 1).padStart(2, '0');
}

const thBase = 'px-2 py-2 text-[0.65rem] font-semibold uppercase tracking-[0.02em] text-white';
const tdBase = 'px-2 py-1.5 align-middle';
</script>

<template>
    <div>
        <div class="overflow-x-auto border border-gray-200">
            <table class="w-full min-w-[900px] border-collapse">
                <thead>
                    <tr class="bg-navy">
                        <th :class="thBase" class="w-[4%] text-left">Nº</th>
                        <th :class="thBase" class="w-[11%] text-left">Código</th>
                        <th :class="thBase" class="text-left">Descrição</th>
                        <th :class="thBase" class="w-[9%] text-right">Qtd.</th>
                        <template v-if="tipoProdutoServico === 'produto'">
                            <th :class="thBase" class="w-[12%] text-right">Unit. s/IPI</th>
                            <th :class="thBase" class="w-[12%] text-right">Unit. c/IPI</th>
                            <th :class="thBase" class="w-[12%] text-right">Total s/IPI</th>
                            <th :class="thBase" class="w-[12%] text-right">Total c/IPI</th>
                        </template>
                        <template v-else>
                            <th :class="thBase" class="w-[15%] text-right">Vlr. unitário</th>
                            <th :class="thBase" class="w-[15%] text-right">Vlr. total</th>
                        </template>
                    </tr>
                </thead>

                <tbody>
                    <template v-for="(item, idx) in itens()" :key="idx">
                        <tr class="border-b border-gray-100" :class="idx % 2 === 1 ? 'bg-[#FAFBFC]' : ''">
                            <td :class="tdBase" class="text-[0.75rem] text-gray-400">{{ numeroLinha(idx) }}</td>

                            <td :class="tdBase" class="relative">
                                <input
                                    :value="item.cod_produto"
                                    type="text"
                                    class="doc-campo"
                                    placeholder="buscar"
                                    @input="buscarProduto(idx, $event.target.value)"
                                />
                                <div
                                    v-if="painelAberto === `busca-${idx}` && resultadosProduto.length"
                                    class="absolute left-0 top-full z-20 mt-1 w-72 border border-gray-200 bg-white shadow-lg"
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
                            </td>

                            <td :class="tdBase">
                                <input
                                    :value="item.descricao"
                                    type="text"
                                    required
                                    class="doc-campo"
                                    placeholder="Descrição do item"
                                    @input="atualizarItem(idx, 'descricao', $event.target.value)"
                                />
                            </td>

                            <td :class="tdBase">
                                <input
                                    :value="item.quantidade"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    required
                                    class="doc-campo text-right"
                                    @input="atualizarItem(idx, 'quantidade', $event.target.value)"
                                />
                            </td>

                            <template v-if="tipoProdutoServico === 'produto'">
                                <td :class="tdBase" class="text-right text-[0.8rem] text-gray-500">
                                    {{ formatNumero(valorUnitarioSemIpi(item)) }}
                                </td>
                                <td :class="tdBase">
                                    <input
                                        :value="item.valor_unitario"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        required
                                        class="doc-campo text-right"
                                        @input="atualizarItem(idx, 'valor_unitario', $event.target.value)"
                                    />
                                </td>
                                <td :class="tdBase" class="text-right text-[0.8rem] text-gray-500">
                                    {{ formatNumero(valorTotal(item, false)) }}
                                </td>
                                <td :class="tdBase" class="text-right text-[0.8rem] font-semibold text-navy">
                                    {{ formatNumero(valorTotal(item, true)) }}
                                </td>
                            </template>
                            <template v-else>
                                <td :class="tdBase">
                                    <input
                                        :value="item.valor_unitario"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        required
                                        class="doc-campo text-right"
                                        @input="atualizarItem(idx, 'valor_unitario', $event.target.value)"
                                    />
                                </td>
                                <td :class="tdBase" class="text-right text-[0.8rem] font-semibold text-navy">
                                    {{ formatNumero(valorTotal(item, true)) }}
                                </td>
                            </template>
                        </tr>

                        <!-- Linha de apoio: some do documento impresso, existe só pra precificar. -->
                        <tr class="border-b border-gray-200 bg-slate-50/70">
                            <td class="px-2 pb-2 text-[0.6rem] uppercase tracking-wide text-gray-300">&mdash;</td>
                            <td class="px-2 pb-2" :colspan="tipoProdutoServico === 'produto' ? 8 : 5">
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[0.7rem] text-gray-500">
                                    <span class="rounded-sm bg-gray-200 px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-gray-600">
                                        {{ ROTULOS_TIPO[item.tipo_item] ?? item.tipo_item }}
                                    </span>

                                    <!--
                                        ⚠️ SOMENTE LEITURA, e isto conserta um bug real do beta.
                                        Aqui havia um <input type="number" step="0.01"> recebendo o preço
                                        de `produtos.preco_tabela`, que é decimal(12,4). Um preço com 3 ou 4
                                        casas deixava o campo em `stepMismatch`, e a validação NATIVA do
                                        navegador abortava o submit do formulário — sem passar pelo servidor
                                        e com o balão de erro longe do campo. Para quem usava, "o botão salvar
                                        não faz nada"; o jeito era apagar dois dígitos depois da vírgula.
                                        Sem input, não há step, e a classe inteira do bug deixa de existir.

                                        Além disso o preço de tabela é a REFERÊNCIA do fornecedor e o
                                        denominador do desconto que define o nível de aprovação — não é campo
                                        de digitação do vendedor. Ele entra pela busca de produto
                                        (selecionarProduto) e fica vazio quando o item não tem produto
                                        vinculado, caso que o NivelAprovacaoCalculator já trata.
                                    -->
                                    <span class="flex items-center gap-1.5">
                                        Preço tabela
                                        <span
                                            class="border-b border-dashed border-gray-300 px-0 py-0.5 font-medium text-gray-600"
                                            :title="item.preco_tabela ? `Valor de tabela: ${item.preco_tabela}` : 'Item sem produto vinculado'"
                                        >{{ item.preco_tabela ? formatBRL(item.preco_tabela) : '—' }}</span>
                                    </span>

                                    <span v-if="descontoInfo(item)" :class="descontoInfo(item).cor" class="font-semibold">
                                        {{ descontoInfo(item).label }}
                                    </span>

                                    <label v-if="tipoProdutoServico === 'produto'" class="flex items-center gap-1">
                                        <input
                                            type="checkbox"
                                            :checked="item.calcula_ipi"
                                            :disabled="item.tipo_item === 'etiqueta'"
                                            class="rounded border-gray-300 text-cyan focus:ring-cyan"
                                            @change="toggleCalcIpi(idx, item)"
                                        />
                                        IPI
                                    </label>

                                    <span class="text-gray-400">Total do item {{ formatBRL(valorTotal(item, true)) }}</span>

                                    <button
                                        v-if="item.tipo_item === 'etiqueta' && isAdmin"
                                        type="button"
                                        class="font-medium text-teal underline"
                                        @click="toggleCalculadora(idx)"
                                    >
                                        {{ painelAberto === `calc-${idx}` ? 'Fechar calculadora' : 'Calculadora de precificação' }}
                                    </button>
                                    <span v-else-if="item.tipo_item === 'etiqueta' && item.etiqueta_calc" class="text-gray-400">
                                        Precificado via calculadora ({{ item.etiqueta_calc.materiaPrimaDesc ?? 'matéria-prima removida' }})
                                    </span>

                                    <button
                                        v-if="itens().length > 1"
                                        type="button"
                                        class="tbl-acao tbl-acao-danger ml-auto"
                                        title="Remover item"
                                        @click="removerItem(idx)"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </div>

                                <InputError :message="errors[`itens.${idx}.descricao`]" class="mt-1" />
                                <InputError :message="errors[`itens.${idx}.quantidade`]" class="mt-1" />
                                <InputError :message="errors[`itens.${idx}.valor_unitario`]" class="mt-1" />

                                <EtiquetaCalculadora
                                    v-if="painelAberto === `calc-${idx}`"
                                    :materias-primas="materiasPrimas"
                                    :etiqueta-calc="item.etiqueta_calc"
                                    @aplicar="(resultado) => aplicarCalculadora(idx, resultado)"
                                />
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="mt-2 flex items-center justify-between">
            <button
                type="button"
                class="bg-teal px-3 py-1.5 text-xs font-medium text-white hover:bg-teal/90"
                @click="mostrarTipoModal = true"
            >
                + Adicionar item
            </button>
            <p class="text-[0.68rem] text-gray-400">Valores em reais (R$).</p>
        </div>

        <ItemTipoModal :show="mostrarTipoModal" @close="mostrarTipoModal = false" @escolher="adicionarItem" />
    </div>
</template>
