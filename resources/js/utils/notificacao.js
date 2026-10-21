import { router } from '@inertiajs/vue3';

/**
 * Abre o destino de uma notificação. Usado pelo sino e pela tela de histórico — um lugar
 * só (Regra de ouro nº 8), senão uma das duas volta a abrir planilha pelo Inertia.
 *
 * ⚠️ Link de ARQUIVO não passa pelo router do Inertia.
 *
 * `router.visit` faz um XHR e espera uma resposta de página; recebendo um .xlsx ele
 * descarta a resposta e o clique não faz nada — sem download e sem erro visível, que
 * era o sintoma da planilha da Carteira. Quem sabe baixar é o navegador: como a rota
 * responde com `Content-Disposition: attachment`, atribuir a location dispara o
 * download sem sair da página. Quem decide o que é arquivo é o servidor
 * (Notificacao::TIPOS_DOWNLOAD), não uma lista de tipos repetida aqui.
 */
export function abrirLinkDaNotificacao(notificacao) {
    if (!notificacao.link) return;

    if (notificacao.download) {
        window.location.href = notificacao.link;
        return;
    }

    router.visit(notificacao.link);
}

export function formatarDataHora(iso) {
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}
