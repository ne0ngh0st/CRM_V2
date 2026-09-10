<script setup>
import { useForm } from '@inertiajs/vue3';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';

/*
 * Confirmação com componente próprio (e não pelo `useConfirmacao`) porque aqui existe
 * `useForm`: o servidor pode recusar a exclusão com um erro de validação, e é dentro do
 * modal que ele precisa aparecer.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    orcamento: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({});

function fechar() {
    form.clearErrors();
    emit('close');
}

function excluir() {
    form.delete(route('orcamentos.destroy', props.orcamento.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <ConfirmacaoModal
        :show="show"
        titulo="Excluir orçamento"
        :subtitulo="orcamento?.clienteNome ?? ''"
        :mensagem="orcamento ? `Excluir o orçamento de ${orcamento.clienteNome} permanentemente?` : ''"
        detalhe="Os itens do orçamento vão junto, e não há como desfazer."
        rotulo-confirmar="Excluir"
        tom="danger"
        :processando="form.processing"
        :erro="form.errors.orcamento ?? ''"
        @confirmar="excluir"
        @close="fechar"
    />
</template>
