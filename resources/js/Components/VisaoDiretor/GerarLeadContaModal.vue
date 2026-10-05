<script setup>
/**
 * "Gerar lead": a conta-alvo sem loja nossa vira lead no funil, já na carteira de quem
 * vai trabalhá-la. Regras no servidor (`LeadDaConta`); aqui só a escolha do responsável.
 *
 * O responsável sugerido é o especialista do segmento (a estrela em Equipe → Segmentos),
 * quando ele tem código de vendedor — é quem a diretoria já apontou para aquele mercado.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ModalPadrao from '@/Components/ModalPadrao.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatInteiro } from '@/utils/formato';

const props = defineProps({
    show: { type: Boolean, default: false },
    conta: { type: Object, default: null },
    segmento: { type: Object, default: null },
    responsaveis: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({ responsavel_id: '', recado: '', cnpj: '' });

// A conta já tem lead da prospecção (sem dono): o modal atribui aquele em vez de criar.
const atribuindo = computed(() => (props.conta?.leads?.length ?? 0) > 0);

const especialistaElegivel = computed(() => {
    const id = props.segmento?.especialista?.id;

    return id && props.responsaveis.some((r) => r.id === id) ? id : null;
});

watch(() => props.show, (aberto) => {
    if (! aberto) return;

    form.clearErrors();
    form.responsavel_id = especialistaElegivel.value ?? '';
    form.recado = '';
    form.cnpj = '';
});

function gerar() {
    form.post(route('visao-diretor.maiores.gerar-lead', props.conta.id), {
        preserveScroll: true,
        only: ['dados', 'filtros'],
        onSuccess: () => emit('close'),
    });
}

const campo = 'mt-1 block w-full rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan';
const rotulo = 'text-xs font-semibold uppercase tracking-wide text-gray-400';
</script>

<template>
    <ModalPadrao :show="show" :titulo="atribuindo ? 'Atribuir lead' : 'Gerar lead'" :subtitulo="conta?.nome ?? ''" max-width="lg" @close="emit('close')">
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                <circle cx="9" cy="8" r="3.5" />
                <path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke-linecap="round" />
                <path d="M18 8v6M15 11h6" stroke-linecap="round" />
            </svg>
        </template>

        <form id="form-gerar-lead" class="flex flex-col gap-3" @submit.prevent="gerar">
            <p v-if="atribuindo" class="text-sm text-gray-600">
                A prospecção já trouxe esta rede como lead, <strong>sem dono</strong>. Ele passa para quem você
                escolher, que é avisado pelo sino. Segmento, UF, site e filiais vão junto numa observação.
            </p>
            <p v-else class="text-sm text-gray-600">
                A rede entra no funil de leads como <strong>Novo</strong>, na carteira de quem você escolher, e a pessoa é
                avisada pelo sino. Segmento, UF, site e filiais vão junto numa observação.
                Se a rede já estiver em Leads sem dono, aquele lead é atribuído — não nasce outro.
            </p>

            <div v-if="conta" class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                <span class="font-medium text-gray-800">{{ conta.nome }}</span>
                <template v-if="segmento"> · {{ segmento.nome }}</template>
                <template v-if="conta.uf"> · {{ conta.uf }}</template>
                <template v-if="conta.filiaisMercado"> · {{ formatInteiro(conta.filiaisMercado) }} filiais no mercado</template>
            </div>

            <div>
                <label :class="rotulo" for="lead_responsavel">Responsável</label>
                <select id="lead_responsavel" v-model="form.responsavel_id" required :class="campo">
                    <option value="" disabled>Escolha quem vai trabalhar o lead</option>
                    <option v-for="r in responsaveis" :key="r.id" :value="r.id">
                        {{ r.nome }} ({{ r.codVendedor }}){{ r.id === especialistaElegivel ? ' — especialista do segmento' : '' }}
                    </option>
                </select>
                <InputError :message="form.errors.responsavel_id" class="mt-1" />
            </div>

            <!--
                O CNPJ é o que impede o lead duplicado: se a prospecção já trouxe a empresa
                (sem dono), o servidor reaproveita aquele lead em vez de criar outro.
            -->
            <div v-if="! atribuindo">
                <label :class="rotulo" for="lead_cnpj">CNPJ da matriz <span class="normal-case">(opcional, evita lead duplicado)</span></label>
                <input id="lead_cnpj" v-model="form.cnpj" type="text" inputmode="numeric" maxlength="18" :class="campo" placeholder="00.000.000/0000-00" />
                <InputError :message="form.errors.cnpj" class="mt-1" />
            </div>

            <div>
                <label :class="rotulo" for="lead_recado">Recado para o responsável <span class="normal-case">(opcional)</span></label>
                <textarea id="lead_recado" v-model="form.recado" rows="3" maxlength="2000" :class="campo" placeholder="Ex.: falar com o comprador regional, já pediram cotação de bobina" />
                <InputError :message="form.errors.recado" class="mt-1" />
            </div>
        </form>

        <template #footer>
            <SecondaryButton type="button" @click="emit('close')">Cancelar</SecondaryButton>
            <PrimaryButton type="submit" form="form-gerar-lead" :disabled="form.processing || ! form.responsavel_id">
                {{ form.processing ? 'Salvando…' : (atribuindo ? 'Atribuir lead' : 'Gerar lead') }}
            </PrimaryButton>
        </template>
    </ModalPadrao>
</template>
