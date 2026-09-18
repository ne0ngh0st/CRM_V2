/**
 * O NOME de cada status da Carteira — pill, filtro e card do Painel leem daqui.
 *
 * ⚠️ Só o rótulo mudou em 2026-09-18 (pedido do Tony): "Inativando" virou "Perdendo" e
 * "Inativo" virou "A trabalhar". As CHAVES (`inativando`/`inativo`) continuam as mesmas —
 * estão na URL (`?status=inativo`, link salvo), no servidor, no cache e nas views do
 * Power BI. Renomear a chave junto quebraria tudo isso sem ganho nenhum para quem lê.
 *
 * Os rótulos servem no singular e no plural ("12 Perdendo", "12 A trabalhar"), por isso
 * o card do Painel usa o mesmo mapa em vez de ter a versão plural própria.
 * ⚠️ `CarteiraExport.php` tem a cópia do lado do servidor — mudou aqui, muda lá.
 */
export const ROTULOS_STATUS_CARTEIRA = {
    ativo: 'Ativo',
    inativando: 'Perdendo',
    inativo: 'A trabalhar',
};

export const TONS_STATUS_CARTEIRA = {
    ativo: 'ok',
    inativando: 'warn',
    inativo: 'danger',
};

/**
 * As colunas da Carteira que o servidor sabe ordenar, na ordem em que aparecem na tabela.
 *
 * Existe porque a ordenação passou a ter DOIS acessos: o clique no cabeçalho no desktop e
 * o seletor "Ordenar por" no celular — onde o `<thead>` não existe, já que a tabela virou
 * cartão. Com a lista escrita duas vezes, uma coluna ordenável nova entraria no cabeçalho
 * e ficaria inalcançável no celular, sem erro nenhum aparecer.
 *
 * ⚠️ ESPELHA `CarteiraController::ORDENACOES` e não pode ganhar chave que não esteja lá:
 * a whitelist do servidor é o que impede o valor da query string de virar `ORDER BY`, e
 * campo desconhecido cai no default silenciosamente — o clique "não faz nada" e parece bug.
 * Foi por isso que 'grupo' e 'segmento' saíram das duas pontas em 2026-08-29.
 */
export const ORDENACOES_CARTEIRA = [
    { campo: 'nome', rotulo: 'Cliente' },
    { campo: 'vendedor', rotulo: 'Vendedor' },
    { campo: 'estado', rotulo: 'Estado' },
    { campo: 'status', rotulo: 'Status' },
    { campo: 'ultima_compra', rotulo: 'Última Compra' },
    { campo: 'ultimo_contato', rotulo: 'Último contato' },
];

/**
 * O rótulo de uma coluna ordenável, para o cabeçalho e o seletor dizerem a MESMA palavra.
 *
 * ⚠️ Avisa alto em vez de devolver vazio: sem isto, um `campo` com erro de digitação
 * renderizaria um `<th>` em branco — o tipo de defeito que passa por "estilo estranho".
 */
export function rotuloOrdenacao(campo) {
    const achado = ORDENACOES_CARTEIRA.find((o) => o.campo === campo);

    if (! achado) {
        console.error(`[carteira] coluna ordenável desconhecida: "${campo}". Confira ORDENACOES_CARTEIRA e CarteiraController::ORDENACOES.`);

        return campo;
    }

    return achado.rotulo;
}
