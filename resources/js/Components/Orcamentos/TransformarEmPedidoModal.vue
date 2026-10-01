<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import InputError from '@/Components/InputError.vue';
import { ROTULOS_TIPO_VENDA } from '@/constants/orcamentos.js';

/*
 * "Transformar em pedido" no Portal Autopel.
 *
 * Componente próprio (e não `useConfirmacao`) porque tem campos e `useForm`: a data de
 * entrega e, no FOB, a transportadora — e o servidor pode recusar os dois com erro de
 * validação, que precisa aparecer aqui dentro. Orçamento anterior ao campo "Tipo de venda"
 * (2026-10-01) escolhe o tipo aqui também, e ele fica gravado no orçamento.
 *
 * ⚠️ Desde a versão de 2026-09-30 da API o pedido nasce DIRETO na fila de aprovação,
 * não mais como rascunho que alguém da Autopel completava. Não há volta pelo fluxo
 * normal — por isso a data é confirmada aqui, com o orçamento na frente.
 *
 * Reenvio (`portalReenvio`): houve uma tentativa cujo resultado não se sabe. O corpo
 * vai idêntico, com a mesma chave — pedir data nova aqui seria prometer uma coisa e
 * mandar outra.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({ data_entrega: '', transportadora: '', tipo_venda: '' });

const reenvio = computed(() => !!props.orcamento?.portalReenvio);
const fob = computed(() => props.orcamento?.tipoFrete === 'FOB');
// Orçamento antigo pode não ter frete (a coluna é nullable), e o Portal passou a exigi-lo.
// Em vez de deixar clicar e voltar erro, o modal manda direto para a edição.
const semFrete = computed(() => !reenvio.value && !['CIF', 'FOB'].includes(props.orcamento?.tipoFrete));
// Sem valor pré-marcado, igual ao formulário: consumo e revenda têm TES diferente.
const pedeTipoVenda = computed(() => !reenvio.value && !semFrete.value && !props.orcamento?.tipoVenda);

// Piso do seletor: amanhã, no fuso do navegador. O ERP é quem valida de verdade.
const amanha = computed(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
});

const brl = (v) => Number(v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const mensagem = computed(() => {
    if (!props.orcamento) return '';
    const base = `O orçamento #${props.orcamento.id} (${brl(props.orcamento.valorTotal)}) vira um pedido no Portal Autopel`;
    if (reenvio.value) {
        return `${base}. A tentativa anterior ficou sem resposta — este clique reenvia exatamente a mesma requisição.`;
    }
    return semFrete.value
        ? `O orçamento #${props.orcamento.id} não tem o tipo de frete (CIF ou FOB) definido, e o Portal passou a exigi-lo para criar o pedido.`
        : `${base}, com frete ${props.orcamento.tipoFrete}`
            + (props.orcamento.tipoVenda ? `, como ${ROTULOS_TIPO_VENDA[props.orcamento.tipoVenda].toLowerCase()}.` : '.');
});

const detalhe = computed(() => semFrete.value
    ? 'Edite o orçamento, escolha o frete e volte aqui.'
    : reenvio.value
    ? 'Se o pedido já tiver sido criado, o Portal devolve o mesmo número e nada é duplicado.'
    : 'O pedido entra direto na fila de aprovação e não volta atrás pelo CRM. A data é um pedido: o ERP pode ajustá-la, e a data confirmada chega pelo sino.');

// Abrir de novo começa limpo — o formulário não pode carregar a data de outro orçamento.
watch(() => props.show, (aberto) => {
    if (aberto) {
        form.reset();
        form.clearErrors();
    }
});

function fechar() {
    form.clearErrors();
    emit('close');
}

function enviar() {
    if (semFrete.value) {
        router.visit(route('orcamentos.editar', props.orcamento.id));
        return;
    }

    form
        .transform((d) => ({
            data_entrega: reenvio.value ? null : d.data_entrega,
            // ⚠️ Só no FOB. No CIF quem escolhe a transportadora é o ERP, e a API não aceita o campo.
            transportadora: fob.value && !reenvio.value ? d.transportadora.trim() : null,
            tipo_venda: pedeTipoVenda.value ? d.tipo_venda : null,
        }))
        .post(route('orcamentos.portal', props.orcamento.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => fechar(),
        });
}
</script>

<template>
    <ConfirmacaoModal
        :show="show"
        :titulo="reenvio ? 'Reenviar pedido' : 'Transformar em pedido'"
        :subtitulo="orcamento?.clienteNome ?? ''"
        :mensagem="mensagem"
        :detalhe="detalhe"
        :rotulo-confirmar="semFrete ? 'Editar orçamento' : reenvio ? 'Reenviar' : 'Transformar em pedido'"
        tom="atencao"
        :processando="form.processing"
        @confirmar="enviar"
        @close="fechar"
    >
        <div v-if="!reenvio && !semFrete" class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="block">
                <span class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Entrega desejada</span>
                <input
                    v-model="form.data_entrega"
                    type="date"
                    :min="amanha"
                    required
                    class="mt-1 block w-full rounded border-gray-300 py-1.5 text-sm focus:border-cyan focus:ring-cyan"
                />
                <InputError :message="form.errors.data_entrega" class="mt-1" />
            </label>

            <label v-if="pedeTipoVenda" class="block">
                <span class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Tipo de venda</span>
                <select
                    v-model="form.tipo_venda"
                    class="mt-1 block w-full rounded border-gray-300 py-1.5 text-sm focus:border-cyan focus:ring-cyan"
                >
                    <option value="" disabled>Selecione</option>
                    <option v-for="(rotulo, valor) in ROTULOS_TIPO_VENDA" :key="valor" :value="valor">{{ rotulo }}</option>
                </select>
                <span class="mt-1 block text-[0.65rem] leading-3 text-gray-400">Fica gravado no orçamento.</span>
                <InputError :message="form.errors.tipo_venda" class="mt-1" />
            </label>

            <label v-if="fob" class="block">
                <span class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Transportadora (cód. Protheus)</span>
                <input
                    v-model="form.transportadora"
                    type="text"
                    maxlength="20"
                    placeholder="Ex.: T00042"
                    class="mt-1 block w-full rounded border-gray-300 py-1.5 text-sm uppercase focus:border-cyan focus:ring-cyan"
                />
                <span class="mt-1 block text-[0.65rem] leading-3 text-gray-400">A que o cliente contratou.</span>
                <InputError :message="form.errors.transportadora" class="mt-1" />
            </label>
        </div>
    </ConfirmacaoModal>
</template>
