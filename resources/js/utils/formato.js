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

/** "18/09/2026" a partir de um ISO do servidor. Vazio vira travessão. */
export function dataCurta(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString('pt-BR');
}

/** "18/09/2026 14:32" a partir de um ISO do servidor. */
export function dataHora(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

/** "1,4 MB" / "320 KB" — tamanho de arquivo para quem vai baixar. */
export function tamanhoArquivo(bytes) {
    if (!bytes) return '—';
    const mb = bytes / 1048576;

    return mb >= 1 ? `${mb.toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

const PERCENTUAL = new Intl.NumberFormat('pt-BR', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 });
const MOEDA_CURTA = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 });
const INTEIRO = new Intl.NumberFormat('pt-BR');

/** "4,0%" a partir de uma FRAÇÃO (0.04). `null` vira "—": sem denominador não há percentual. */
export function formatPercentual(fracao) {
    return fracao === null || fracao === undefined ? '—' : PERCENTUAL.format(fracao);
}

/** "R$ 1,2 mi" — para KPI e célula de tabela, onde o valor exato vai no `title`. */
export function formatBRLCurto(valor) {
    return MOEDA_CURTA.format(valor || 0);
}

/** "12.345" */
export function formatInteiro(valor) {
    return INTEIRO.format(valor || 0);
}

/** "2026-08-01" → "01/08/2026"; vazio vira "—". Data pura, sem fuso: não passa por `Date`. */
export function formatDataCurta(iso) {
    if (! iso) return '—';
    const [a, m, d] = String(iso).slice(0, 10).split('-');

    return `${d}/${m}/${a}`;
}
