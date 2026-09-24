<script setup>
/**
 * As versões da observação de uma conta-alvo, da mais recente para a mais antiga.
 *
 * Uma lista só para os dois lugares que a mostram — o modal de edição (quem vai mudar o
 * texto vê o que já foi dito) e o modal aberto pela célula da tabela. Mesmo desenho da
 * lista do `ObservacoesModal` (borda cyan à esquerda).
 *
 * `versao` é qualquer valor que mude quando o histórico mudou (a contagem de versões que o
 * servidor manda): mudou, recarrega. Sem isso, o modal reaberto logo depois de salvar
 * mostraria a lista antiga.
 */
import { ref, watch } from 'vue';

const props = defineProps({
    contaId: { type: Number, required: true },
    versao: { type: [Number, String], default: 0 },
    alturaMaxima: { type: String, default: 'max-h-56' },
});

const versoes = ref([]);
const carregando = ref(false);
const erro = ref(false);
let ultima = 0;

async function carregar() {
    // Resposta atrasada de outra conta não pode sobrescrever a atual.
    const esta = ++ultima;
    carregando.value = true;
    erro.value = false;

    try {
        const resposta = await fetch(route('visao-diretor.maiores.observacoes', props.contaId), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! resposta.ok) throw new Error(`HTTP ${resposta.status}`);

        const dados = await resposta.json();
        if (esta === ultima) versoes.value = dados;
    } catch {
        if (esta === ultima) erro.value = true;
    } finally {
        if (esta === ultima) carregando.value = false;
    }
}

watch(() => [props.contaId, props.versao], carregar, { immediate: true });
</script>

<template>
    <div>
        <p v-if="carregando && ! versoes.length" class="text-xs text-gray-400">Carregando histórico…</p>
        <p v-else-if="erro" class="text-xs text-red-700">Não foi possível carregar o histórico.</p>
        <p v-else-if="! versoes.length" class="rounded border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400">
            Nenhuma observação registrada ainda.
        </p>

        <ul v-else class="space-y-2 overflow-y-auto pr-1" :class="alturaMaxima">
            <li
                v-for="(v, i) in versoes"
                :key="v.id"
                class="rounded border border-l-2 px-3 py-2"
                :class="i === 0 ? 'border-gray-200 border-l-cyan bg-gray-50' : 'border-gray-100 border-l-gray-300 bg-white'"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <p class="truncate text-xs font-semibold text-gray-700">
                        {{ v.autor }}
                        <span v-if="i === 0" class="ml-1 text-[0.65rem] font-semibold uppercase tracking-wide text-cyan-dark">vigente</span>
                    </p>
                    <p class="shrink-0 text-[0.65rem] text-gray-400">{{ v.criadoEm }}</p>
                </div>
                <p v-if="v.texto" class="mt-1 whitespace-pre-wrap text-sm leading-snug" :class="i === 0 ? 'text-gray-800' : 'text-gray-500'">{{ v.texto }}</p>
                <p v-else class="mt-1 text-sm italic text-gray-400">Observação apagada.</p>
            </li>
        </ul>
    </div>
</template>
