<script setup>
import { computed } from 'vue';
import { urlDoMapa } from '@/utils/mapas';

/*
 * Endereço que abre o Google Maps em aba nova. O texto exibido é o do slot — cada tela
 * já formata o endereço do seu jeito —, e as props dizem para ONDE o link aponta.
 *
 * Quando não há link possível (endereço incompleto, ou documento de pessoa física — ver
 * `utils/mapas.js`), renderiza o mesmo texto sem link, para a tela não precisar de `v-if`.
 */
const props = defineProps({
    logradouro: { type: String, default: '' },
    municipio: { type: String, default: '' },
    uf: { type: String, default: '' },
    cep: { type: String, default: '' },
    // CNPJ/CPF do dono do endereço. CPF não ganha link.
    documento: { type: String, default: '' },
});

const url = computed(() => urlDoMapa(props));
</script>

<template>
    <!-- Aba nova, como o WhatsApp: o Maps por cima do CRM custaria a página e os filtros. -->
    <a
        v-if="url"
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        title="Abrir no Google Maps"
        class="group inline-flex items-start gap-1 text-left hover:text-teal"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal">
            <path d="M12 21s-6.5-5.6-6.5-10.5a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21Z" stroke-linejoin="round" />
            <circle cx="12" cy="10.5" r="2.25" />
        </svg>
        <span class="group-hover:underline"><slot /></span>
    </a>
    <span v-else><slot /></span>
</template>
