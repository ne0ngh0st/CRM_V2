<script setup>
import { useForm } from '@inertiajs/vue3';
import ConfirmacaoModal from '@/Components/ConfirmacaoModal.vue';

/*
 * Confirmação com componente próprio (e não pelo `useConfirmacao`) porque aqui existe
 * `useForm`: o servidor recusa a exclusão em alguns casos, e o motivo tem que aparecer
 * dentro do modal.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    usuario: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({});

function fechar() {
    form.clearErrors();
    emit('close');
}

function excluir() {
    form.delete(route('equipe.destroy', props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => fechar(),
    });
}
</script>

<template>
    <ConfirmacaoModal
        :show="show"
        titulo="Excluir usuário"
        :subtitulo="usuario?.nome ?? ''"
        :mensagem="usuario ? `Excluir ${usuario.nome} permanentemente?` : ''"
        detalhe="Se a pessoa só saiu da equipe, prefira desativar — assim o histórico dela continua ligado a um usuário."
        rotulo-confirmar="Excluir"
        tom="danger"
        :processando="form.processing"
        :erro="form.errors.usuario ?? ''"
        @confirmar="excluir"
        @close="fechar"
    />
</template>
