import { onScopeDispose, reactive } from 'vue';

/*
 * `confirm()` do navegador, com a cara do sistema.
 *
 * Existe para que trocar o diálogo nativo pelo `ConfirmacaoModal` não obrigue cada tela
 * a inventar seu próprio par de `ref`s (`mostrarModal` + `itemPendente`) e a picar a
 * função em duas — a que abre e a que executa. O call-site continua lendo de cima para
 * baixo, na mesma forma de antes:
 *
 *     if (! await confirmar({ titulo: 'Excluir lead', mensagem: '…', tom: 'danger' })) return;
 *     router.delete(…);
 *
 * No template, uma linha só:
 *
 *     <ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
 *
 * ⚠️ `v-bind="confirmacao"` é o que mantém isto barato: os rótulos, o tom e o `show`
 * viajam juntos. Por isso o rótulo do botão chama-se `rotuloConfirmar` e não `confirmar`
 * — o nome curto colidiria com a leitura do `@confirmar` para quem lê o template.
 */

const PADRAO = {
    titulo: 'Confirmar',
    subtitulo: '',
    mensagem: '',
    detalhe: '',
    rotuloConfirmar: 'Confirmar',
    rotuloCancelar: 'Cancelar',
    tom: 'neutro',
    erro: '',
};

export function useConfirmacao() {
    const confirmacao = reactive({ ...PADRAO, show: false, processando: false });

    let resolver = null;

    function responder(resposta) {
        const pendente = resolver;
        resolver = null;
        confirmacao.show = false;
        confirmacao.processando = false;
        pendente?.(resposta);
    }

    function confirmar(opcoes = {}) {
        // Uma pendente aberta só acontece por clique duplo em botões diferentes. Resolver
        // como "não" é o certo: promessa órfã deixaria o `await` do primeiro clique preso
        // para sempre, e a tela não mostra mais aquele modal para ninguém responder.
        responder(false);

        Object.assign(confirmacao, PADRAO, opcoes, { show: true, processando: false });

        return new Promise((resolve) => {
            resolver = resolve;
        });
    }

    // Sair da página com o modal aberto não pode deixar o `await` pendurado.
    onScopeDispose(() => responder(false));

    return {
        confirmacao,
        confirmar,
        aoConfirmar: () => responder(true),
        aoCancelar: () => responder(false),
    };
}
