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
 * As duas listas abaixo espelham `App\Models\Lead`. Mexeu aqui, mexe lá.
 */

/**
 * A ESTEIRA: a negociação, em ordem. É ela que diz qual é a próxima etapa e o que conta
 * como negócio em jogo.
 *
 * ⚠️ NÃO derivar a ordem de ETAPAS_ABERTAS. As duas foram a mesma lista até "Outros"
 * existir, e continuar usando ETAPAS_ABERTAS faria o botão "→" de um lead em Negociação
 * apontar para "Outros" — o atalho de "já tratei esse, joga pro próximo" jogaria o
 * negócio para fora do funil, sem nada quebrar na tela.
 */
export const ETAPAS_ESTEIRA = ['novo', 'em_contato', 'orcamento', 'negociacao'];

/**
 * "Outros" é coluna do quadro, mas não é etapa de negociação: é o que chegou como lead e
 * não é venda (SAC, licitação, currículo, fornecedor). Fica no fim, depois do que é
 * negócio — ver `Lead::ETAPA_OUTROS` para o porquê de a triagem ser manual.
 */
export const ETAPA_OUTROS = 'outros';

/** As colunas do quadro, na ordem: a esteira mais o desvio. */
export const ETAPAS_ABERTAS = [...ETAPAS_ESTEIRA, ETAPA_OUTROS];

/** Desfechos: tiram o card do quadro, mas continuam sendo valor de `etapa` e filtráveis. */
export const ETAPAS_FECHADAS = ['ganho', 'perdido'];

/**
 * Tudo que `leads.etapa` aceita — espelha `Lead::ETAPAS`, e é o que o filtro da tela
 * oferece.
 *
 * ⚠️ O dropdown TEM que sair daqui. Até 2026-09-17 ele listava `ativo`, `inativo` e
 * `convertido`, valores do enum anterior à separação dos eixos (2026-09-03): o controller
 * descartava os três por não estarem em `Lead::ETAPAS` e o filtro simplesmente não fazia
 * nada — sem erro, sem tela vazia, apenas a lista inteira de volta. Mesmo formato do
 * filtro de status dos pedidos, em que 5 das 6 opções devolviam tela vazia.
 */
export const ETAPAS_LEAD = [...ETAPAS_ABERTAS, ...ETAPAS_FECHADAS];

export const ROTULOS_ETAPA_LEAD = {
    novo: 'Novo',
    em_contato: 'Em contato',
    orcamento: 'Orçamento',
    negociacao: 'Negociação',
    outros: 'Outros',
    ganho: 'Ganho',
    perdido: 'Perdido',
};

export const TONS_ETAPA_LEAD = {
    novo: 'neutral',
    em_contato: 'warn',
    orcamento: 'warn',
    negociacao: 'ok',
    outros: 'neutral',
    ganho: 'ok',
    perdido: 'danger',
};

/**
 * A próxima etapa da esteira, ou null quando não há para onde avançar. Espelha
 * `Lead::proximaEtapa()` — o quadro precisa dela para a atualização otimista, antes de o
 * servidor responder.
 *
 * ⚠️ Etapa fora da esteira ("outros", "ganho", "perdido") devolve null. Sem essa guarda,
 * o `indexOf` devolveria -1 e o `+1` cairia em 0: um card em "Outros" passaria a exibir
 * "avançar para Novo" e a triagem se desfaria num clique.
 */
export function proximaDaEsteira(etapa) {
    const i = ETAPAS_ESTEIRA.indexOf(etapa);

    return i === -1 ? null : ETAPAS_ESTEIRA[i + 1] ?? null;
}

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
