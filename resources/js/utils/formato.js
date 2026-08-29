// Formatação de número/moeda usada no documento de orçamento.
// Estava copiada em Form.vue, ItensTabela.vue e OrcamentoSheet.vue — Regra de ouro nº 8.

const MOEDA = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const DECIMAL = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** "R$ 1.234,56" — usado onde o símbolo faz falta (resumo financeiro, KPIs). */
export function formatBRL(valor) {
    return MOEDA.format(valor || 0);
}

/**
 * "1.234,56" — sem símbolo. É o formato da tabela de itens: o "R$" aparece uma vez
 * no cabeçalho da coluna em vez de se repetir em toda célula.
 */
export function formatNumero(valor) {
    return DECIMAL.format(valor || 0);
}
