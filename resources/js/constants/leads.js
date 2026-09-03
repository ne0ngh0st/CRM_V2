/**
 * ⚠️ `etapa` e `status` são EIXOS DIFERENTES, e confundi-los foi o defeito que o funil
 * veio corrigir. Até 2026-09-03 a coluna `status` valia `ativo | inativo | convertido |
 * excluido` — ou seja, misturava o estado do REGISTRO com o estágio da NEGOCIAÇÃO, e por
 * isso "convertido" aparecia como alternativa a "inativo".
 *
 * Hoje:
 *   status → só a lixeira (ativo | excluido). Não vira badge na tela.
 *   etapa  → onde a negociação está. É o que o quadro e a tabela mostram.
 *
 * A ordem de ETAPAS_ABERTAS é a mesma do enum PHP (`App\Models\Lead::ETAPAS_ABERTAS`) e
 * define a ordem das colunas do quadro. Mexeu aqui, mexe lá.
 */
export const ETAPAS_ABERTAS = ['novo', 'em_contato', 'orcamento', 'negociacao'];

export const ROTULOS_ETAPA_LEAD = {
    novo: 'Novo',
    em_contato: 'Em contato',
    orcamento: 'Orçamento',
    negociacao: 'Negociação',
    ganho: 'Ganho',
    perdido: 'Perdido',
};

export const TONS_ETAPA_LEAD = {
    novo: 'neutral',
    em_contato: 'warn',
    orcamento: 'warn',
    negociacao: 'ok',
    ganho: 'ok',
    perdido: 'danger',
};

export const ROTULOS_ORIGEM_LEAD = {
    sistema: 'Sistema',
    manual: 'Manual',
    wordpress: 'WordPress',
};

export const TONS_ORIGEM_LEAD = {
    sistema: 'neutral',
    manual: 'warn',
    wordpress: 'ok',
};

/**
 * "parado há X dias" — o indicador que faz alguém agir.
 *
 * ⚠️ Vem de `etapa_alterada_em`, NÃO de `updated_at`: qualquer edição do lead toca o
 * `updated_at`, e uma correção de telefone "reanimaria" um lead esquecido há meses.
 *
 * ⚠️ Para os leads que já existiam quando o funil entrou, o carimbo inicial é o
 * `updated_at` da migração — não havia registro melhor. Nos primeiros dias o número é
 * APROXIMADO, e só fica exato depois do primeiro movimento real de cada lead.
 */
export function diasParado(paradoDesde) {
    if (!paradoDesde) return null;

    const ms = Date.now() - new Date(paradoDesde).getTime();

    return Math.max(0, Math.floor(ms / 86400000));
}

export function rotuloParado(paradoDesde) {
    const dias = diasParado(paradoDesde);
    if (dias === null) return '';
    if (dias === 0) return 'hoje';
    if (dias === 1) return 'há 1 dia';

    return `há ${dias} dias`;
}
