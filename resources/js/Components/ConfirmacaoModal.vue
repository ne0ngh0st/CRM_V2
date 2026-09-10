<script setup>
import ModalPadrao from '@/Components/ModalPadrao.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';

/*
 * Modal de confirmação do sistema — o substituto do `window.confirm()`.
 *
 * ⚠️ NENHUMA tela deve chamar `confirm()`/`alert()` do navegador. O diálogo nativo
 * ignora a identidade visual, muda de cara em cada navegador, não sabe distinguir
 * "excluir" de "só conferir", e no Chrome ainda ganha a opção "não deixar este site
 * abrir mais caixas" — que, marcada, faz a ação seguinte acontecer SEM confirmação
 * nenhuma, ou não acontecer, sem aviso. Confirmação é parte da interface.
 *
 * Normalmente não se usa este componente direto: `useConfirmacao()` embala ele num
 * `await` que mantém o call-site parecido com o `if (! confirm(...)) return;` antigo.
 * Use direto só quando a confirmação já mora num componente próprio (ex.: quando há
 * `useForm` e erro de validação a exibir).
 *
 * O tom escolhe o ícone e a cor do botão — e é a única decisão de cor aqui:
 *   danger  → destrói dado (excluir, remover)
 *   atencao → irreversível ou pesado, mas não destrutivo (ação para fora do CRM,
 *             reimportação em massa, entrar na pele de outro usuário)
 *   neutro  → só quer um "tem certeza?" antes de seguir
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, required: true },
    // Linha de contexto no header preto (razão social, nº do documento...).
    subtitulo: { type: String, default: '' },
    mensagem: { type: String, default: '' },
    // Consequência do "sim", no bloco tingido. É o que o confirm() nativo empurrava
    // pra dentro de um `\n\n` e ninguém lia.
    detalhe: { type: String, default: '' },
    rotuloConfirmar: { type: String, default: 'Confirmar' },
    rotuloCancelar: { type: String, default: 'Cancelar' },
    tom: {
        type: String,
        default: 'neutro',
        validator: (v) => ['danger', 'atencao', 'neutro'].includes(v),
    },
    // Trava os dois botões enquanto a requisição corre, pra não disparar duas vezes.
    processando: { type: Boolean, default: false },
    erro: { type: String, default: '' },
});

const emit = defineEmits(['confirmar', 'close']);

// ⚠️ Sobre o header preto do ModalPadrao. `text-amber`/`text-red-400` ficam no próprio
// <svg>, e não no wrapper, porque o wrapper de lá é `text-cyan` fixo — classe no
// elemento ganha da herdada.
const ICONE = {
    danger: 'text-red-400',
    atencao: 'text-amber',
    neutro: 'text-cyan',
};

// Bloco do `detalhe`, sobre o corpo branco.
const TINTA = {
    danger: 'border-l-red-500 bg-red-50 text-red-800',
    atencao: 'border-l-amber bg-amber/10 text-gray-700',
    neutro: 'border-l-cyan bg-gray-50 text-gray-600',
};

/*
 * Mesma forma dos botões do resto do sistema (PrimaryButton/DangerButton); o que muda
 * é só a cor. Fica aqui, e não em três `v-if` importando três componentes, porque este
 * componente É a fonte única do botão de confirmar.
 * ⚠️ `amber-dark` e não `amber`: o âmbar da marca sobre branco não sustenta texto branco.
 */
const BOTAO = {
    danger: 'bg-red-600 hover:bg-red-500 focus:ring-red-500 active:bg-red-700',
    atencao: 'bg-amber-dark hover:bg-amber-dark/90 focus:ring-amber active:bg-amber-dark',
    neutro: 'bg-gray-800 hover:bg-gray-700 focus:ring-gray-500 active:bg-gray-900',
};
</script>

<template>
    <ModalPadrao
        :show="show"
        :titulo="titulo"
        :subtitulo="subtitulo"
        max-width="md"
        :closeable="!processando"
        @close="emit('close')"
    >
        <template #icon>
            <svg
                v-if="tom === 'neutro'"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="h-5 w-5"
                :class="ICONE[tom]"
            >
                <circle cx="12" cy="12" r="9" />
                <path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.5.2-.7.6-.7 1.1v.5" stroke-linecap="round" />
                <circle cx="12" cy="16.6" r="0.6" fill="currentColor" stroke="none" />
            </svg>
            <svg
                v-else
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="h-5 w-5"
                :class="ICONE[tom]"
            >
                <path d="M10.3 3.9 2.5 17.4a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linejoin="round" />
                <path d="M12 9v4.2" stroke-linecap="round" />
                <circle cx="12" cy="16.8" r="0.7" fill="currentColor" stroke="none" />
            </svg>
        </template>

        <!-- `whitespace-pre-line` mantém a quebra de quem passa texto de mais de um
             parágrafo, sem obrigar cada chamada a virar markup. -->
        <p v-if="mensagem" class="whitespace-pre-line text-sm leading-relaxed text-gray-700">
            {{ mensagem }}
        </p>

        <p v-if="detalhe" class="mt-3 rounded border border-transparent border-l-2 px-3 py-2 text-xs leading-snug" :class="TINTA[tom]">
            {{ detalhe }}
        </p>

        <!-- Para o caso raro em que a confirmação precisa mostrar mais que texto
             (uma lista do que vai junto, um campo de motivo). -->
        <slot />

        <InputError :message="erro" class="mt-3" />

        <template #footer>
            <SecondaryButton type="button" :disabled="processando" @click="emit('close')">
                {{ rotuloCancelar }}
            </SecondaryButton>
            <button
                type="button"
                :disabled="processando"
                class="inline-flex items-center rounded-md border border-transparent px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                :class="BOTAO[tom]"
                @click="emit('confirmar')"
            >
                {{ processando ? 'Aguarde…' : rotuloConfirmar }}
            </button>
        </template>
    </ModalPadrao>
</template>
