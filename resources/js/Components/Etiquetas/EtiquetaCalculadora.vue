<script setup>
import { computed, reactive, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    materiasPrimas: { type: Array, default: () => [] },
    etiquetaCalc: { type: Object, default: null },
});

const emit = defineEmits(['aplicar']);

const dados = reactive({
    largura_util: props.etiquetaCalc?.larguraUtil ?? '',
    gap_lateral: props.etiquetaCalc?.gapLateral ?? 5,
    altura_util: props.etiquetaCalc?.alturaUtil ?? '',
    gap_supinf: props.etiquetaCalc?.gapSupinf ?? 3,
    metros_rolo: props.etiquetaCalc?.metrosRolo ?? '',
    qtd_etiquetas: props.etiquetaCalc?.qtdEtiquetas ?? '',
    materia_prima_id: props.etiquetaCalc?.materiaPrimaId ?? '',
    preco_venda: props.etiquetaCalc?.precoVenda ?? '',
});

const conferindo = ref(false);
const erroConferencia = ref('');

const materiaSelecionada = computed(() =>
    props.materiasPrimas.find((mp) => String(mp.id) === String(dados.materia_prima_id)) ?? null,
);

const resultado = computed(() => {
    const larguraUtil = parseFloat(dados.largura_util) || 0;
    const gapLateral = parseFloat(dados.gap_lateral) || 0;
    const alturaUtil = parseFloat(dados.altura_util) || 0;
    const gapSupinf = parseFloat(dados.gap_supinf) || 0;
    const metrosRolo = parseFloat(dados.metros_rolo) || 0;
    const qtdEtiquetas = parseFloat(dados.qtd_etiquetas) || 0;
    const precoM2 = materiaSelecionada.value ? materiaSelecionada.value.precoM2 : 0;
    const precoVenda = parseFloat(dados.preco_venda) || 0;

    const larguraTotalM = (larguraUtil + gapLateral) / 1000;
    const alturaTotalM = (alturaUtil + gapSupinf) / 1000;
    const m2PorEtiqueta = larguraTotalM * alturaTotalM;
    const labelsPorRolo = alturaTotalM > 0 ? metrosRolo / alturaTotalM : 0;
    const metrosNecessarios = qtdEtiquetas * alturaTotalM;
    const m2TotalConsumido = larguraTotalM * metrosRolo;
    const custoTotal = precoM2 * m2TotalConsumido;
    const precoSugerido = custoTotal / (1 - 0.3);
    const margemBrutaPct = precoVenda > 0 ? ((precoVenda - custoTotal) / precoVenda) * 100 : null;

    return {
        larguraTotalM,
        alturaTotalM,
        m2PorEtiqueta,
        labelsPorRolo,
        metrosNecessarios,
        m2TotalConsumido,
        custoTotal,
        precoSugerido,
        margemBrutaPct,
    };
});

function formatBRL(valor) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor || 0);
}

async function conferirNoServidor() {
    if (!dados.materia_prima_id) {
        erroConferencia.value = 'Selecione a matéria-prima.';

        return;
    }

    conferindo.value = true;
    erroConferencia.value = '';

    try {
        const { data } = await axios.post(route('etiquetas.calcular'), {
            largura_util: parseFloat(dados.largura_util) || 0,
            gap_lateral: parseFloat(dados.gap_lateral) || 0,
            altura_util: parseFloat(dados.altura_util) || 0,
            gap_supinf: parseFloat(dados.gap_supinf) || 0,
            metros_rolo: parseFloat(dados.metros_rolo) || 0,
            qtd_etiquetas: parseFloat(dados.qtd_etiquetas) || 0,
            materia_prima_id: dados.materia_prima_id,
            preco_venda: parseFloat(dados.preco_venda) || null,
        });

        dados.custoTotalServidor = data.custoTotal;
    } catch (e) {
        erroConferencia.value = e.response?.data?.message ?? 'Não foi possível conferir com o servidor.';
    } finally {
        conferindo.value = false;
    }
}

function aplicar() {
    emit('aplicar', {
        valorUnitario: parseFloat(dados.preco_venda) || 0,
        quantidade: parseFloat(dados.qtd_etiquetas) || 0,
        etiquetaCalc: {
            larguraUtil: parseFloat(dados.largura_util) || 0,
            gapLateral: parseFloat(dados.gap_lateral) || 0,
            alturaUtil: parseFloat(dados.altura_util) || 0,
            gapSupinf: parseFloat(dados.gap_supinf) || 0,
            metrosRolo: parseFloat(dados.metros_rolo) || 0,
            qtdEtiquetas: parseFloat(dados.qtd_etiquetas) || 0,
            materiaPrimaId: dados.materia_prima_id,
            materiaPrimaDesc: materiaSelecionada.value?.descMp ?? null,
            precoVenda: parseFloat(dados.preco_venda) || 0,
            custoTotal: resultado.value.custoTotal,
            precoSugerido: resultado.value.precoSugerido,
            margemBrutaPct: resultado.value.margemBrutaPct,
        },
    });
}
</script>

<template>
    <div class="mt-2 rounded border border-amber/40 bg-amber/5 p-3">
        <p class="mb-2 text-[0.65rem] font-bold uppercase tracking-wide text-amber">Calculadora de precificação (admin)</p>

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Largura útil (mm)
                <input v-model="dados.largura_util" type="number" min="0" step="0.1" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Gap lateral (mm)
                <input v-model="dados.gap_lateral" type="number" min="0" step="0.1" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Altura útil (mm)
                <input v-model="dados.altura_util" type="number" min="0" step="0.1" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Gap sup./inf. (mm)
                <input v-model="dados.gap_supinf" type="number" min="0" step="0.1" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Metros do rolo
                <input v-model="dados.metros_rolo" type="number" min="0" step="0.01" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Qtd. etiquetas
                <input v-model="dados.qtd_etiquetas" type="number" min="0" step="1" class="rounded border-gray-300 text-xs" />
            </label>
            <label class="col-span-2 flex flex-col gap-0.5 text-xs text-gray-600">
                Matéria-prima
                <select v-model="dados.materia_prima_id" class="rounded border-gray-300 text-xs">
                    <option value="">Selecione</option>
                    <option v-for="mp in materiasPrimas" :key="mp.id" :value="mp.id">{{ mp.descMp }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-0.5 text-xs text-gray-600">
                Preço de venda (R$)
                <input v-model="dados.preco_venda" type="number" min="0" step="0.01" class="rounded border-gray-300 text-xs" />
            </label>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 rounded bg-white p-2 text-xs text-gray-600 sm:grid-cols-4">
            <p>m² por etiqueta: <strong>{{ resultado.m2PorEtiqueta.toFixed(4) }}</strong></p>
            <p>Etiquetas/rolo: <strong>{{ resultado.labelsPorRolo.toFixed(0) }}</strong></p>
            <p>Custo total: <strong>{{ formatBRL(resultado.custoTotal) }}</strong></p>
            <p>Preço sugerido (30%): <strong>{{ formatBRL(resultado.precoSugerido) }}</strong></p>
            <p v-if="resultado.margemBrutaPct !== null" class="col-span-2" :class="resultado.margemBrutaPct < 0 ? 'font-bold text-red-600' : ''">
                Margem bruta: <strong>{{ resultado.margemBrutaPct.toFixed(1) }}%</strong>
            </p>
        </div>

        <p v-if="erroConferencia" class="mt-1 text-xs text-red-600">{{ erroConferencia }}</p>

        <div class="mt-3 flex items-center gap-2">
            <button type="button" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50" :disabled="conferindo" @click="conferirNoServidor">
                {{ conferindo ? 'Conferindo...' : 'Conferir com o servidor' }}
            </button>
            <button type="button" class="rounded bg-teal px-3 py-1 text-xs font-medium text-white hover:bg-teal/90" @click="aplicar">
                Aplicar ao item
            </button>
        </div>
    </div>
</template>
