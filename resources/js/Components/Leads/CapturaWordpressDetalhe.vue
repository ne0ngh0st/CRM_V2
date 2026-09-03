<script setup>
/**
 * O que o cliente preencheu no formulário do site, em forma de ficha.
 *
 * ⚠️ Quem lê isto é vendedor, não técnico. Até 2026-09-03 este conteúdo era um
 * `<pre>{{ JSON.stringify(payload, null, 2) }}</pre>` — para achar o telefone do cliente
 * era preciso garimpar chave crua de plugin do WordPress (`mc4wp-PHONE`, `your-name`,
 * `itens[]`...). O JSON continua existindo, mas recolhido e só para admin, porque é o que
 * permite diagnosticar um formato de payload novo vindo do site.
 *
 * Campo sem valor simplesmente não aparece: linha "Cidade —" não informa nada e só
 * afasta o que importa.
 */
import { CAMPOS_CAPTURA, CAMPO_MENSAGEM, ROTULOS_FONTE_CAPTURA } from '@/constants/capturaWordpress';
import { computed, ref } from 'vue';

const props = defineProps({
    captura: { type: Object, required: true },
});

const mostrarTecnico = ref(false);

const preenchidos = computed(() =>
    CAMPOS_CAPTURA.filter((campo) => {
        const valor = props.captura.campos?.[campo.chave];

        return valor !== null && valor !== undefined && String(valor).trim() !== '';
    }).map((campo) => ({ ...campo, valor: props.captura.campos[campo.chave] })),
);

const mensagem = computed(() => {
    const valor = props.captura.campos?.[CAMPO_MENSAGEM.chave];

    return valor && String(valor).trim() !== '' ? valor : null;
});

const rotuloFonte = computed(
    () => ROTULOS_FONTE_CAPTURA[props.captura.fonte] ?? props.captura.fonte,
);

/**
 * O bloco técnico pode trazer o payload como objeto (JSON válido) ou como string crua
 * (quando o que foi gravado não era JSON). Os dois casos são reais — ver o docblock de
 * LeadController::camposCrusDoEnvelope.
 */
const payloadFormatado = computed(() => {
    const payload = props.captura.tecnico?.payload;

    return typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
});
</script>

<template>
    <div>
        <p class="mb-3 text-xs text-gray-500">
            {{ rotuloFonte }} · recebido em {{ captura.recebidoEm }}
            <span v-if="captura.formulario"> · {{ captura.formulario }}</span>
        </p>

        <dl v-if="preenchidos.length" class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            <div v-for="campo in preenchidos" :key="campo.chave" class="flex flex-col">
                <dt class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">
                    {{ campo.rotulo }}
                </dt>
                <dd class="text-sm text-gray-700">{{ campo.valor }}</dd>
            </div>
        </dl>

        <p v-else class="text-sm text-gray-400">
            O site não enviou nenhum campo que o sistema saiba reconhecer.
            <span v-if="captura.tecnico">Os dados crus estão abaixo.</span>
        </p>

        <div v-if="mensagem" class="mt-4">
            <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-400">
                {{ CAMPO_MENSAGEM.rotulo }}
            </p>
            <p class="mt-1 whitespace-pre-line border-l-2 border-cyan bg-gray-50 px-3 py-2 text-sm text-gray-700">
                {{ mensagem }}
            </p>
        </div>

        <!-- Só admin recebe `tecnico` do servidor; o v-if aqui é apresentação, não segurança. -->
        <div v-if="captura.tecnico" class="mt-5 border-t border-gray-200 pt-3">
            <button
                type="button"
                class="flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-gray-700"
                @click="mostrarTecnico = !mostrarTecnico"
            >
                <span>{{ mostrarTecnico ? '▾' : '▸' }}</span>
                Dados técnicos
            </button>

            <div v-if="mostrarTecnico" class="mt-2 space-y-2">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-[0.7rem]">
                    <div v-if="captura.tecnico.remoteAddr" class="flex flex-col">
                        <dt class="font-semibold uppercase tracking-wide text-gray-400">IP de origem</dt>
                        <dd class="text-gray-600">{{ captura.tecnico.remoteAddr }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="font-semibold uppercase tracking-wide text-gray-400">Tentativas de promoção</dt>
                        <dd class="text-gray-600">{{ captura.tecnico.tentativas }}</dd>
                    </div>
                    <div v-if="captura.tecnico.erro" class="col-span-2 flex flex-col">
                        <dt class="font-semibold uppercase tracking-wide text-gray-400">Último erro</dt>
                        <dd class="text-red-600">{{ captura.tecnico.erro }}</dd>
                    </div>
                    <div v-if="captura.tecnico.userAgent" class="col-span-2 flex flex-col">
                        <dt class="font-semibold uppercase tracking-wide text-gray-400">User agent</dt>
                        <dd class="break-all text-gray-600">{{ captura.tecnico.userAgent }}</dd>
                    </div>
                </dl>

                <pre class="max-h-80 overflow-auto rounded border border-gray-200 bg-gray-50 p-3 text-[0.7rem] leading-4 text-gray-700">{{ payloadFormatado }}</pre>
            </div>
        </div>
    </div>
</template>
