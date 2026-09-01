export const ROTULOS_STATUS_PEDIDO = {
    separacao: 'Separação',
    bloqueio: 'Bloqueio',
    wms: 'WMS',
    liberado: 'Liberado',
    faturado: 'Faturado',
    pendente_totvs: 'Aguardando classificação do TOTVS',
};

export const TONS_STATUS_PEDIDO = {
    separacao: 'neutral',
    bloqueio: 'danger',
    wms: 'neutral',
    liberado: 'ok',
    faturado: 'ok',
    pendente_totvs: 'neutral',
};

export const ROTULOS_SITUACAO_PEDIDO = {
    atrasado: 'Atrasado',
    vencendo: 'Vencendo',
    no_prazo: 'No prazo',
    sem_previsao: 'Sem previsão',
};

export const TONS_SITUACAO_PEDIDO = {
    atrasado: 'danger',
    vencendo: 'warn',
    no_prazo: 'ok',
    sem_previsao: 'neutral',
};

// Natureza do faturamento — vem do RLT 232 (TIPO_FAT). Produto emite NF-e;
// serviço emite RPS/NFS-e, e é por isso que os dois números coexistem no pedido.
export const ROTULOS_TIPO_FATURAMENTO = {
    produto: 'Produto',
    servico: 'Serviço',
};
