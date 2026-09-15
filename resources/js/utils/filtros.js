/**
 * Quantos filtros estão aplicados numa tela de listagem.
 *
 * ⚠️ NÃO É COSMÉTICO: é o que impede o botão "Filtros" do celular de esconder um recorte
 * ativo. Abaixo de 640px o `PageHero` colapsa a faixa de filtros atrás de um botão — sem
 * o número no botão, o usuário vê uma lista recortada sem nenhuma pista de que ela está
 * filtrada, que é a mesma classe de problema do card da Carteira que não batia com a lista
 * que ele abria (2026-09-15).
 *
 * Nasceu de doze cópias: cada página tinha o seu `temFiltrosAtivos` com a lista de campos
 * escrita à mão, e o aviso do Excel dependia dela. Agora a lista de campos continua sendo
 * de cada página (ela é que sabe quais são), mas a REGRA de "isto conta como filtro?" mora
 * aqui — inclusive o caso do valor neutro que não é string vazia (`situacao: 'todos'`).
 *
 * @param {object} filtros valores atuais (o `reactive` da página)
 * @param {Array<string|[string, *]>} campos nome do campo, ou `[nome, valorNeutro]`
 * @returns {number}
 */
export function contarFiltrosAtivos(filtros, campos) {
    return campos.reduce((total, campo) => {
        const [nome, neutro] = Array.isArray(campo) ? campo : [campo, ''];
        const valor = filtros[nome];

        // `null`/`undefined` contam como neutro: campo opcional que o servidor não mandou
        // não é filtro aplicado. Vários `temFiltrosAtivos` antigos comparavam só com `''`
        // e, por isso, contariam `null` como filtro ativo.
        const ativo = valor !== null && valor !== undefined && valor !== neutro;

        return total + (ativo ? 1 : 0);
    }, 0);
}
