/**
 * Compartilhar um arquivo pelo menu nativo do aparelho (WhatsApp, e-mail, Drive…),
 * via Web Share API.
 *
 * Existe porque abrir o PDF em aba nova não basta no celular: com o CRM instalado como
 * app (PWA), o PDF abre num visualizador sem botão de compartilhar, e o vendedor não
 * tinha como mandar o orçamento ao cliente.
 *
 * ⚠️ `navigator.share` exige "gesto do usuário" recente. Buscar o arquivo antes leva
 * tempo e o iOS pode recusar com `NotAllowedError` — por isso `compartilharArquivo()`
 * devolve 'precisa-toque' nesse caso: quem chama guarda o arquivo já baixado e pede um
 * segundo toque, que aí compartilha na hora.
 */

/** O aparelho sabe compartilhar ARQUIVO (não só link)? */
export function podeCompartilharArquivo() {
    if (typeof navigator === 'undefined' || ! navigator.canShare) return false;

    try {
        const teste = new File([''], 'teste.pdf', { type: 'application/pdf' });
        return navigator.canShare({ files: [teste] });
    } catch {
        return false;
    }
}

/** Baixa a URL (com a sessão do usuário) e devolve um File pronto para compartilhar. */
export async function baixarComoArquivo(url, nome) {
    const resposta = await fetch(url, { credentials: 'same-origin' });
    if (! resposta.ok) throw new Error(`Falha ao baixar (${resposta.status})`);

    const blob = await resposta.blob();
    return new File([blob], nome, { type: blob.type || 'application/pdf' });
}

/**
 * @returns {Promise<'ok'|'cancelado'|'precisa-toque'>}
 */
export async function compartilharArquivo(arquivo, titulo) {
    try {
        await navigator.share({ files: [arquivo], title: titulo });
        return 'ok';
    } catch (erro) {
        if (erro?.name === 'AbortError') return 'cancelado';
        if (erro?.name === 'NotAllowedError') return 'precisa-toque';
        throw erro;
    }
}
