<script setup>
/*
 * "Solicitar inativação", no rodapé do Cartão CNPJ: pede ao Cadastro para inativar no
 * TOTVS um cliente (filial) com CNPJ irregular na Receita. O pedido vai numa lista diária
 * (18h, dias úteis), com quem clicou em cópia. A regra mora no servidor (`PedidoDeInativacao`); aqui só o botão, a confirmação
 * e o estado.
 *
 * ⚠️ Mora DENTRO do modal do cartão, e só lá — o Tony recusou botão a mais na Carteira
 * (2026-10-01). O caminho é: CNPJ vermelho → cartão → este botão. Ele ocupa o lugar do
 * antigo "Atualizar da Receita".
 *
 * Depois do pedido vira um botão desabilitado com "solicitada em dd/mm" — o mesmo cliente
 * não é pedido duas vezes.
 */
import { computed, reactive, ref } from 'vue';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';
import { ROTULOS_SITUACAO_RECEITA } from '@/constants/receita.js';
import { postJson } from '@/utils/csrf.js';

const props = defineProps({
    // { id, codCliente, loja, razaoSocial }
    cliente: { type: Object, required: true },
    // Situação na Receita, como o servidor mandou junto do cartão.
    situacao: { type: String, default: null },
    // Pedido já enviado (`{em, por}`) ou null.
    solicitada: { type: Object, default: null },
    // Feature desligada no servidor (`receita.inativacao_habilitada`): botão desabilitado.
    manutencao: { type: Boolean, default: false },
});

// Estado local: depois do pedido o botão muda sem recarregar o cartão.
const inativacao = ref(props.solicitada);
const modal = reactive({ show: false, processando: false, erro: '' });

const rotuloSituacao = computed(() => ROTULOS_SITUACAO_RECEITA[props.situacao] ?? props.situacao ?? '');

function abrir() {
    Object.assign(modal, { show: true, processando: false, erro: '' });
}

async function enviar() {
    modal.processando = true;
    modal.erro = '';

    try {
        const res = await postJson(route('carteira.solicitarInativacao', props.cliente.id));
        const corpo = await res.json().catch(() => ({}));

        // 409 = já tinha sido pedida (por outra pessoa, noutra aba): mostra o pedido existente.
        if (res.ok || res.status === 409) {
            inativacao.value = corpo.inativacao ?? inativacao.value;
            modal.show = false;
            return;
        }

        modal.erro = corpo.mensagem ?? `Não foi possível enviar (erro ${res.status}).`;
    } catch {
        modal.erro = 'Sem conexão com o servidor. Tente de novo.';
    } finally {
        modal.processando = false;
    }
}
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded border px-4 py-2 text-xs font-semibold uppercase tracking-widest transition disabled:cursor-default"
        :class="inativacao || manutencao
            ? 'border-gray-300 bg-gray-50 text-gray-500'
            : 'border-amber bg-amber/10 text-amber-dark hover:bg-amber/20'"
        :disabled="!!inativacao || manutencao"
        :title="inativacao
            ? `Pedido registrado em ${inativacao.em}${inativacao.por ? ` por ${inativacao.por}` : ''}`
            : manutencao ? 'Função temporariamente indisponível' : 'Pede ao Cadastro a inativação deste cliente no TOTVS'"
        @click="abrir"
    >
        {{ inativacao ? `Inativação solicitada em ${inativacao.em}` : manutencao ? 'Inativação em manutenção' : 'Solicitar inativação' }}
    </button>

    <ConfirmacaoModal
        :show="modal.show"
        titulo="Solicitar inativação"
        :subtitulo="`${cliente.razaoSocial} · ${cliente.codCliente}/${cliente.loja}`"
        :mensagem="`Situação do CNPJ na Receita Federal: ${rotuloSituacao}. Pedir ao Cadastro (cadastro.geral@autopel.com) para inativar este cliente no TOTVS?`"
        detalhe="O pedido vai na lista diária ao Cadastro, às 18h (dias úteis), com você em cópia. O CRM não inativa nada: o cliente continua aparecendo aqui até o Cadastro inativá-lo no TOTVS."
        rotulo-confirmar="Solicitar"
        tom="atencao"
        :processando="modal.processando"
        :erro="modal.erro"
        @confirmar="enviar"
        @close="modal.show = false"
    />
</template>
