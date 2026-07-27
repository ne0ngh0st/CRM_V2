<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    prefill: { type: Object, default: null },
    meta: { type: Object, default: () => ({}) },
    opcoes: { type: Object, required: true },
});

const diametroSelect = ref('');
const adesivoSelect = ref('');
const diametroCustom = ref('');
const adesivoCustom = ref('');

const form = useForm({
    nomenclatura: '',
    personalizacao: '',
    unidade_venda: '',
    quantidade_caixa: '',
    metragem: '',
    medidas: '',
    diametro_tubete: '',
    aplicacao: '',
    tipo_adesivo: '',
    estoque_seguranca_sn: '',
    estoque_seguranca: '',
    saida_rolo: 'f1',
    observacoes: '',
});

watch(
    () => props.prefill,
    (p) => {
        if (!p) return;
        form.nomenclatura = p.nomenclatura || '';
        form.personalizacao = p.personalizacao || '';
        form.unidade_venda = p.unidadeVenda || '';
        form.quantidade_caixa = p.quantidadeCaixa != null ? String(p.quantidadeCaixa) : '';
        form.metragem = p.metragem != null ? String(p.metragem) : '';
        form.medidas = p.medidas || '';
        form.aplicacao = p.aplicacao || '';
        form.estoque_seguranca_sn = p.estoqueSegurancaSn || '';
        form.estoque_seguranca = p.estoqueSeguranca != null ? String(p.estoqueSeguranca) : '';
        form.saida_rolo = p.saidaRolo || 'f1';
        form.observacoes = p.observacoes || '';

        const diametros = props.opcoes.diametros_tubete || [];
        if (p.diametroTubete && diametros.includes(p.diametroTubete)) {
            diametroSelect.value = p.diametroTubete;
            diametroCustom.value = '';
        } else if (p.diametroTubete) {
            diametroSelect.value = '__custom__';
            diametroCustom.value = p.diametroTubete;
        } else {
            diametroSelect.value = '';
            diametroCustom.value = '';
        }

        const adesivos = props.opcoes.tipo_adesivos || [];
        if (p.tipoAdesivo && adesivos.includes(p.tipoAdesivo)) {
            adesivoSelect.value = p.tipoAdesivo;
            adesivoCustom.value = '';
        } else if (p.tipoAdesivo) {
            adesivoSelect.value = '__custom__';
            adesivoCustom.value = p.tipoAdesivo;
        } else {
            adesivoSelect.value = '';
            adesivoCustom.value = '';
        }
    },
);

const saidas = [
    { value: 'f1', label: 'F1', desc: 'Saída pelo pé / base da arte', img: '/images/F1.png' },
    { value: 'f2', label: 'F2', desc: 'Saída pelo topo da arte', img: '/images/F2.png' },
    { value: 'f3', label: 'F3', desc: 'Saída pelo lado esquerdo', img: '/images/F3.png' },
    { value: 'f4', label: 'F4', desc: 'Saída pelo lado direito', img: '/images/F4.png' },
];

const fieldClass = 'mt-1 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan';

function submit() {
    form.diametro_tubete = diametroSelect.value === '__custom__' ? diametroCustom.value : diametroSelect.value;
    form.tipo_adesivo = adesivoSelect.value === '__custom__' ? adesivoCustom.value : adesivoSelect.value;

    form.post(route('cadastros.etiquetas.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.saida_rolo = 'f1';
            diametroSelect.value = '';
            adesivoSelect.value = '';
            diametroCustom.value = '';
            adesivoCustom.value = '';
        },
    });
}
</script>

<template>
    <DarkCard title="Ficha de Etiquetas" subtitle="Solicitação de cadastro de etiqueta para PCP/Cadastro">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <path d="M4 8l4-4h8l4 4v8l-4 4H8l-4-4V8z" stroke-linejoin="round" />
                <circle cx="12" cy="12" r="2" />
            </svg>
        </template>

        <div class="mb-4 grid gap-2 sm:grid-cols-3">
            <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                <p class="text-[0.65rem] font-semibold uppercase text-gray-400">Solicitante</p>
                <p class="text-sm text-gray-800">{{ meta.solicitanteNome }}</p>
            </div>
            <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                <p class="text-[0.65rem] font-semibold uppercase text-gray-400">Data</p>
                <p class="text-sm text-gray-800">{{ new Date().toLocaleDateString('pt-BR') }}</p>
            </div>
            <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                <p class="text-[0.65rem] font-semibold uppercase text-gray-400">Tipo</p>
                <p class="text-sm text-gray-800">Cadastro de Etiquetas</p>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <InputLabel value="Nomenclatura *" class="!text-xs" />
                        <TextInput v-model="form.nomenclatura" class="mt-1 block w-full text-xs" placeholder="Nome conforme arte do cliente" required />
                        <p class="mt-1 text-[0.65rem] text-gray-400">Lacres/selos: prefixe a nomenclatura.</p>
                        <InputError :message="form.errors.nomenclatura" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Personalização" class="!text-xs" />
                            <select v-model="form.personalizacao" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="impresso">Impresso</option>
                                <option value="sem_impressao">Sem impressão</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Unidade de venda" class="!text-xs" />
                            <select v-model="form.unidade_venda" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="caixa">Caixa</option>
                                <option value="unidade">Unidade</option>
                                <option value="pacote_manual">Pacote (manual)</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Quantidade por caixa" class="!text-xs" />
                            <TextInput v-model="form.quantidade_caixa" type="number" min="0" class="mt-1 block w-full text-xs" />
                        </div>
                        <div>
                            <InputLabel value="Metragem total (m)" class="!text-xs" />
                            <TextInput v-model="form.metragem" type="number" min="0" step="0.1" class="mt-1 block w-full text-xs" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Medidas (L x A em mm)" class="!text-xs" />
                            <select v-model="form.medidas" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option v-for="m in opcoes.medidas" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Diâmetro interno do tubete" class="!text-xs" />
                            <select v-model="diametroSelect" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option v-for="d in opcoes.diametros_tubete" :key="d" :value="d">{{ d }}</option>
                                <option value="__custom__">Outro (especificar)</option>
                            </select>
                            <TextInput v-if="diametroSelect === '__custom__'" v-model="diametroCustom" class="mt-2 block w-full text-xs" placeholder="Informe o diâmetro" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Aplicação" class="!text-xs" />
                            <select v-model="form.aplicacao" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option v-for="a in opcoes.aplicacoes" :key="a" :value="a">{{ a }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Tipo de adesivo" class="!text-xs" />
                            <select v-model="adesivoSelect" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option v-for="t in opcoes.tipo_adesivos" :key="t" :value="t">{{ t }}</option>
                                <option value="__custom__">Outro (especificar)</option>
                            </select>
                            <TextInput v-if="adesivoSelect === '__custom__'" v-model="adesivoCustom" class="mt-2 block w-full text-xs" placeholder="Informe o tipo de adesivo" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Estoque de segurança (S/N)" class="!text-xs" />
                        <div class="mt-1 flex gap-4 text-xs text-gray-700">
                            <label class="inline-flex items-center gap-1.5"><input v-model="form.estoque_seguranca_sn" type="radio" value="sim" class="border-gray-300 text-cyan focus:ring-cyan" /> Sim</label>
                            <label class="inline-flex items-center gap-1.5"><input v-model="form.estoque_seguranca_sn" type="radio" value="nao" class="border-gray-300 text-cyan focus:ring-cyan" /> Não</label>
                        </div>
                    </div>
                    <div v-if="form.estoque_seguranca_sn === 'sim'">
                        <InputLabel value="Quantidade estoque de segurança *" class="!text-xs" />
                        <TextInput v-model="form.estoque_seguranca" type="number" min="0" class="mt-1 block w-full text-xs" required />
                        <InputError :message="form.errors.estoque_seguranca" />
                    </div>
                    <fieldset>
                        <legend class="text-xs font-medium text-gray-700">Saída de rolo</legend>
                        <p class="mt-0.5 text-[0.65rem] text-gray-400">Selecione o sentido conforme a arte.</p>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label
                                v-for="s in saidas"
                                :key="s.value"
                                class="cursor-pointer rounded border p-2 text-center transition"
                                :class="form.saida_rolo === s.value ? 'border-cyan bg-cyan/5' : 'border-gray-200 hover:border-gray-300'"
                            >
                                <input v-model="form.saida_rolo" type="radio" :value="s.value" class="sr-only" />
                                <img :src="s.img" :alt="s.label" class="mx-auto h-14 w-auto object-contain" />
                                <p class="mt-1 text-xs font-semibold text-gray-800">{{ s.label }}</p>
                                <p class="text-[0.6rem] leading-tight text-gray-500">{{ s.desc }}</p>
                            </label>
                        </div>
                    </fieldset>
                </div>
                <div>
                    <InputLabel value="Observações" class="!text-xs" />
                    <textarea v-model="form.observacoes" rows="16" class="mt-1 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan" placeholder="Detalhes técnicos, acabamentos, picotes ou instruções." />
                </div>
            </div>
            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Salvar solicitação</PrimaryButton>
            </div>
        </form>
    </DarkCard>
</template>
