<script>
/*
 * Pilha dos modais abertos, COMPARTILHADA por todas as instâncias. Só o do topo responde
 * ao ESC, e o scroll da página só é devolvido quando o último fecha.
 *
 * ⚠️ Existe por causa do modal aberto DE DENTRO de outro — a confirmação de "remover esta
 * imagem" dentro do formulário de faca. O listener de ESC é global e um por instância:
 * sem a pilha, um ESC fechava a confirmação E o formulário por baixo dela, levando junto
 * o que estava sendo editado. O `overflow` tinha o mesmo defeito ao contrário — fechar o
 * de cima destravava o scroll com o de baixo ainda aberto.
 *
 * ⚠️ Fica NESTE bloco, e não no `<script setup>` de baixo: aquele é o corpo do `setup()`
 * e roda uma vez POR INSTÂNCIA — a pilha nasceria vazia em cada modal e todo mundo se
 * acharia o do topo. Foi exatamente esse o primeiro erro aqui.
 */
const pilha = [];
</script>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);
const dialog = ref();
const showSlot = ref(props.show);

// Identidade desta instância dentro da `pilha` (que é do MÓDULO, ver o <script> acima).
const token = {};

const noTopo = () => pilha[pilha.length - 1] === token;

function desempilhar() {
    const i = pilha.indexOf(token);
    if (i !== -1) pilha.splice(i, 1);
    if (!pilha.length) document.body.style.overflow = '';
}

watch(
    () => props.show,
    () => {
        if (props.show) {
            if (!pilha.includes(token)) pilha.push(token);
            document.body.style.overflow = 'hidden';
            showSlot.value = true;

            dialog.value?.showModal();
        } else {
            desempilhar();

            setTimeout(() => {
                dialog.value?.close();
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e) => {
    // `preventDefault` só quando este modal é mesmo quem vai fechar: ele impede o
    // fechamento nativo do <dialog>, que senão sumiria da tela com o `show` ainda true.
    if (e.key === 'Escape' && props.show && noTopo()) {
        e.preventDefault();
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);

    desempilhar();
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
    >
        <div
            class="fixed inset-0 z-50 overflow-y-auto p-4"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transform bg-corp-black/60 transition-all"
                    @click="close"
                />
            </Transition>

            <!--
                Centralização vertical: o scroll fica no container de fora e o flex
                `min-h-full items-center` no de dentro. Não trocar por `flex` direto
                no container que rola — modal mais alto que a tela tem o topo cortado
                e fica inalcançável. O `py-6`/`mb-6` do scaffold Breeze, que alinhava
                tudo no topo, saiu daqui.
            -->
            <div class="pointer-events-none relative flex min-h-full items-center justify-center">
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="pointer-events-auto w-full transform overflow-hidden rounded border border-gray-300 bg-white shadow-2xl transition-all sm:mx-auto"
                        :class="maxWidthClass"
                    >
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </div>
    </dialog>
</template>
