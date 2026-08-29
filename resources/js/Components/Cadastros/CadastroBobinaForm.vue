<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DarkCard from '@/Components/DarkCard.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    prefill: { type: Object, default: null },
    meta: { type: Object, default: () => ({}) },
});

const form = useForm({
    nomenclatura: '',
    personalizacao: '',
    unidade_venda: '',
    quantidade_caixa: '',
    papel: '',
    gramatura: '',
    largura: '',
    metragem: '',
    tubete_obrigatorio: '',
    diametro_tubete: '',
    estoque_seguranca_sn: '',
    estoque_seguranca: '',
    impressao: '',
    rebobinamento: '',
    observacoes: '',
    nf_pedido_tipo: '',
});

watch(
    () => props.prefill,
    (p) => {
        if (!p) return;
        form.nomenclatura = p.nomenclatura || '';
        form.personalizacao = p.personalizacao || '';
        form.unidade_venda = p.unidadeVenda || '';
        form.quantidade_caixa = p.quantidadeCaixa != null ? String(p.quantidadeCaixa) : '';
        form.papel = p.papel || '';
        form.gramatura = p.gramatura || '';
        form.largura = p.largura || '';
        form.metragem = p.metragem != null ? String(p.metragem) : '';
        form.tubete_obrigatorio = p.tubeteObrigatorio || '';
        form.diametro_tubete = p.diametroTubete != null ? String(p.diametroTubete) : '';
        form.estoque_seguranca_sn = p.estoqueSegurancaSn || '';
        form.estoque_seguranca = p.estoqueSeguranca != null ? String(p.estoqueSeguranca) : '';
        form.impressao = p.impressao || '';
        form.rebobinamento = p.rebobinamento || '';
        form.observacoes = p.observacoes || '';
        form.nf_pedido_tipo = p.nfPedidoTipo || '';
    },
);

// Escolher a gramatura sugere o papel correspondente (mesmo comportamento do
// legado, assets/js/solicitacoes-bobinas.js). É só sugestão — dá pra trocar
// depois. Não confundir com o mapa de nomenclatura do título, que é outro:
// aqui 44 → KPR (matéria-prima), no título 44 → TS KPH BC (nome no TOTVS).
// ⚠️ Ligado ao @change do select, não a um watch: watch dispararia no prefill
// também e sobrescreveria o papel que veio salvo.
const GRAMATURA_PARA_PAPEL = {
    44: 'kpr',
    48: 'termicco',
    55: 'termoscript',
};

function sugerirPapelPelaGramatura() {
    const sugerido = GRAMATURA_PARA_PAPEL[form.gramatura];
    if (sugerido) form.papel = sugerido;
}

// O servidor descarta o diâmetro quando o tubete não é obrigatório; limpar aqui
// evita o campo continuar preenchido na tela mostrando um valor que não será gravado.
// Ligado ao @change do radio, não a um watch, pelo mesmo motivo do mapa de gramatura.
function limparDiametroTubete() {
    if (form.tubete_obrigatorio !== 'sim') form.diametro_tubete = '';
}

function submit() {
    form.post(route('cadastros.bobinas.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const fieldClass = 'mt-1 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan';
</script>

<template>
    <DarkCard title="Ficha de Bobinas" subtitle="Solicitação de cadastro de produto bobina para PCP/Cadastro">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <circle cx="12" cy="12" r="7" />
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
                <p class="text-sm text-gray-800">Cadastro de Bobinas</p>
            </div>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-3 lg:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <InputLabel value="Nomenclatura *" class="!text-xs" />
                        <TextInput v-model="form.nomenclatura" class="mt-1 block w-full text-xs" placeholder="Nome conforme arte do cliente" required />
                        <InputError :message="form.errors.nomenclatura" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Personalização" class="!text-xs" />
                            <select v-model="form.personalizacao" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="personalizada">Personalizada</option>
                                <option value="sem_impressao">Sem impressão</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Unidade de venda" class="!text-xs" />
                            <select v-model="form.unidade_venda" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="caixa">Caixa</option>
                                <option value="unidade">Unidade</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Quantidade por caixa" class="!text-xs" />
                        <TextInput v-model="form.quantidade_caixa" type="number" min="0" class="mt-1 block w-full text-xs" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Papel" class="!text-xs" />
                            <select v-model="form.papel" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="termicco">Térmico</option>
                                <option value="termoscript">Termoscript</option>
                                <option value="kpr">KPR</option>
                                <option value="termobank">TERMOBANK</option>
                                <option value="termoticket">Termoticket</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Gramatura (g/m²)" class="!text-xs" />
                            <select v-model="form.gramatura" :class="fieldClass" @change="sugerirPapelPelaGramatura">
                                <option value="">Selecione</option>
                                <option v-for="g in ['44','48','55','72','105','167']" :key="g" :value="g">{{ g }}</option>
                            </select>
                            <p class="mt-1 text-[0.65rem] leading-3 text-gray-400">
                                O título TOTVS segue a gramatura: 44 → TS KPH BC · 48 → TÉRMICO · 55 → TERMOSCRIPT.
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Largura (mm)" class="!text-xs" />
                            <select v-model="form.largura" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option v-for="l in ['50','55','57','58','60','69','76','80','82','88','100','104','105','110','111','112','210','400']" :key="l" :value="l">{{ l }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Metragem (m)" class="!text-xs" />
                            <TextInput v-model="form.metragem" type="number" min="0" step="0.1" class="mt-1 block w-full text-xs" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Uso obrigatório de tubete" class="!text-xs" />
                            <div class="mt-1 flex gap-4 text-xs text-gray-700">
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.tubete_obrigatorio" type="radio" value="sim" class="border-gray-300 text-cyan focus:ring-cyan" @change="limparDiametroTubete" /> Sim</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.tubete_obrigatorio" type="radio" value="nao" class="border-gray-300 text-cyan focus:ring-cyan" @change="limparDiametroTubete" /> Não</label>
                            </div>
                            <InputError :message="form.errors.tubete_obrigatorio" />
                        </div>
                        <div v-if="form.tubete_obrigatorio === 'sim'">
                            <InputLabel value="Diâmetro do tubete (mm) *" class="!text-xs" />
                            <TextInput v-model="form.diametro_tubete" type="number" min="0" step="0.1" class="mt-1 block w-full text-xs" required />
                            <InputError :message="form.errors.diametro_tubete" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Estoque de segurança (S/N)" class="!text-xs" />
                            <div class="mt-1 flex gap-4 text-xs text-gray-700">
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.estoque_seguranca_sn" type="radio" value="sim" class="border-gray-300 text-cyan focus:ring-cyan" /> Sim</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="form.estoque_seguranca_sn" type="radio" value="nao" class="border-gray-300 text-cyan focus:ring-cyan" /> Não</label>
                            </div>
                        </div>
                        <div v-if="form.estoque_seguranca_sn === 'sim'">
                            <InputLabel value="Estoque de segurança *" class="!text-xs" />
                            <TextInput v-model="form.estoque_seguranca" type="number" min="0" class="mt-1 block w-full text-xs" required />
                            <InputError :message="form.errors.estoque_seguranca" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Impressão" class="!text-xs" />
                            <select v-model="form.impressao" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="verso">Verso</option>
                                <option value="frente_lado_termico">Frente (lado térmico)</option>
                                <option value="frente_verso">Frente/Verso</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Rebobinamento" class="!text-xs" />
                            <select v-model="form.rebobinamento" :class="fieldClass">
                                <option value="">Selecione</option>
                                <option value="lado_termico_fora">Lado térmico para fora</option>
                                <option value="lado_termico_dentro">Lado térmico para dentro</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <InputLabel value="Observações" class="!text-xs" />
                        <textarea v-model="form.observacoes" rows="8" class="mt-1 block w-full rounded border-gray-300 text-xs focus:border-cyan focus:ring-cyan" placeholder="Detalhes adicionais ou instruções específicas." />
                    </div>
                    <div class="rounded border border-gray-200 p-3">
                        <p class="text-xs font-semibold uppercase text-gray-500">NF Pedido Tipo</p>
                        <div class="mt-2 space-y-2 text-xs text-gray-700">
                            <label class="flex items-center gap-2"><input v-model="form.nf_pedido_tipo" type="radio" value="venda" class="border-gray-300 text-cyan focus:ring-cyan" /> Venda – NCM 48119010</label>
                            <label class="flex items-center gap-2"><input v-model="form.nf_pedido_tipo" type="radio" value="servico" class="border-gray-300 text-cyan focus:ring-cyan" /> Serviço – NCM 49111090</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <PrimaryButton :disabled="form.processing">Salvar solicitação</PrimaryButton>
            </div>
        </form>
    </DarkCard>
</template>
