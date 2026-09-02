<script setup>
/*
 * Os três botões de canal da coluna "Ações": ligar, WhatsApp e e-mail.
 *
 * Existe como componente porque o que se repete aqui é MARCAÇÃO (três botões, três
 * ícones, três regras de habilitação), não um punhado de utilities — é o critério da
 * Regra de ouro nº 8. Hoje é usado na Carteira e nos Leads; qualquer tela nova que
 * precise contatar alguém reusa isto em vez de recriar os botões.
 *
 * ⚠️ O componente NÃO registra o contato: ele emite `contato` com o canal e quem
 * chama decide a rota (`carteira.ligacao` ou `leads.ligacao`). Foi o jeito de manter
 * a marcação num lugar só sem que o componente precisasse conhecer as rotas.
 */
import { computed } from 'vue';
import { linkEmail, linkTelefone, linkWhatsapp, temWhatsapp } from '@/utils/contato.js';

const props = defineProps({
    telefone: { type: String, default: null },
    email: { type: String, default: null },
});

const emit = defineEmits(['contato']);

const podeWhatsapp = computed(() => !!props.telefone && temWhatsapp(props.telefone));
const podeEmail = computed(() => !!props.email && props.email.includes('@'));

function ligar() {
    if (!props.telefone) return;
    emit('contato', 'telefonica');
    window.location.href = linkTelefone(props.telefone);
}

function whatsapp() {
    if (!podeWhatsapp.value) return;
    emit('contato', 'whatsapp');
    // Aba nova, ao contrário do `tel:`: o WhatsApp Web abriria por cima do CRM e o
    // vendedor perderia a página (e os filtros) da Carteira.
    window.open(linkWhatsapp(props.telefone), '_blank', 'noopener');
}

function enviarEmail() {
    if (!podeEmail.value) return;
    emit('contato', 'email');
    window.location.href = linkEmail(props.email);
}
</script>

<template>
    <button
        type="button"
        :disabled="!telefone"
        :title="telefone ? `Realizar ligação — ${telefone}` : 'Telefone não cadastrado'"
        class="tbl-acao tbl-acao-verde"
        @click="ligar"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M4 5c0 8.284 6.716 15 15 15 1-1.5 1.5-3 1.5-4.5l-4-1.5-1.5 2A11.5 11.5 0 0 1 9 10.5l2-1.5-1.5-4C8 5 6.5 5.5 5 6.5 4.5 5.5 4 5.5 4 5Z" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <button
        type="button"
        :disabled="!podeWhatsapp"
        :title="podeWhatsapp ? `Abrir WhatsApp — ${telefone}` : (telefone ? 'Telefone sem DDD — não dá pra abrir o WhatsApp' : 'Telefone não cadastrado')"
        class="tbl-acao tbl-acao-whats"
        @click="whatsapp"
    >
        <!-- Glifo da própria marca WhatsApp: é preenchido, não traçado como os
             outros ícones — trocar por um contorno genérico tira justamente o que
             faz o botão ser reconhecido sem ler o tooltip. -->
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
        </svg>
    </button>

    <button
        type="button"
        :disabled="!podeEmail"
        :title="podeEmail ? `Enviar e-mail — ${email}` : 'E-mail não cadastrado'"
        class="tbl-acao tbl-acao-teal"
        @click="enviarEmail"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2.5" y="5" width="19" height="14" rx="2" />
            <path d="m3.5 6.5 8.5 6 8.5-6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>
</template>
