/*
 * Situação cadastral na Receita, para exibir. Quem decide o que é "irregular" é o
 * servidor (`SituacaoCadastral::irregular()` — conhecida e diferente de ATIVA); aqui
 * mora só como cada situação aparece na tela.
 */
export const ROTULOS_SITUACAO_RECEITA = {
    ATIVA: 'Ativa',
    BAIXADA: 'Baixada',
    INAPTA: 'Inapta',
    SUSPENSA: 'Suspensa',
    NULA: 'Nula',
    INEXISTENTE: 'Inexistente',
};

// Valores do filtro "Receita" da Carteira — whitelist em `CarteiraController::FILTROS_RECEITA`.
// Só "irregular", por latência: ver o docblock da constante no controller.
export const FILTROS_RECEITA = {
    irregular: 'CNPJ irregular',
};

/** 'YYYY-MM' → 'MM/YYYY'. */
export function mesDaBase(referencia) {
    if (!referencia) return '—';
    const [ano, mes] = referencia.split('-');
    return `${mes}/${ano}`;
}

/** 'YYYY-MM-DD' → 'DD/MM/YYYY', sem passar por Date (fuso). */
export function dataDaSituacao(data) {
    if (!data) return null;
    const [a, m, d] = data.split('-');
    return `${d}/${m}/${a}`;
}
