/**
 * A PILHA DE CAMADAS SOBREPOSTAS do sistema — modal, confirmação e gaveta mobile.
 *
 * Uma camada sobreposta precisa de duas coisas que não podem ser decididas sozinhas:
 * travar o scroll do `<body>` e saber se ela é a de cima (para o ESC fechar só uma).
 * Ambas dependem de TODAS as camadas abertas, então a pilha é única por definição.
 *
 * Isto morava dentro do `Modal.vue` até 2026-09-15 e saiu de lá quando a gaveta do celular
 * passou a precisar do mesmo controle. Duas pilhas seriam pior que nenhuma: cada uma
 * zerando `body.style.overflow` por conta própria, e a que fechasse por último destravando
 * o scroll com a outra ainda aberta — foi exatamente esse defeito que criou a pilha no
 * `Modal.vue`, agora com duas chances de acontecer.
 *
 * ⚠️ O ARRAY É DO MÓDULO, nunca do `setup()`. Um `const pilha = []` dentro do corpo do
 * componente roda uma vez POR INSTÂNCIA: a pilha nasce vazia em cada camada e todas se
 * acham a de cima. Foi esse o primeiro erro na versão original dentro do `Modal.vue`, e o
 * sintoma era o ESC fechar a confirmação E o formulário por baixo dela, levando junto o
 * que estava sendo editado.
 *
 * ⚠️ Cada camada guarda o próprio ESC. O que se compartilha é a pilha, não a tecla: o
 * `Modal.vue` precisa de `preventDefault()` para impedir o fechamento nativo do
 * `<dialog>` (que sumiria da tela com o `show` ainda `true`), e a gaveta não tem
 * `<dialog>`. Unificar o listener obrigaria uma das duas a carregar a condição da outra.
 */
import { onUnmounted } from 'vue';

const pilha = [];

export function usePilhaDeCamadas() {
    /* Identidade desta instância. Objeto vazio serve: só a igualdade por referência importa. */
    const token = {};

    function abrir() {
        if (!pilha.includes(token)) {
            pilha.push(token);
        }

        document.body.style.overflow = 'hidden';
    }

    function fechar() {
        const i = pilha.indexOf(token);

        if (i !== -1) {
            pilha.splice(i, 1);
        }

        /* Devolve o scroll só quando a ÚLTIMA camada sai. */
        if (!pilha.length) {
            document.body.style.overflow = '';
        }
    }

    function noTopo() {
        return pilha[pilha.length - 1] === token;
    }

    /*
     * Camada desmontada com a página ainda travada deixaria o sistema sem scroll e sem
     * nada na tela para fechar. Acontece de verdade: o Inertia troca a página inteira, e
     * um modal aberto durante a navegação é desmontado sem passar pelo `close`.
     */
    onUnmounted(fechar);

    return { abrir, fechar, noTopo };
}
