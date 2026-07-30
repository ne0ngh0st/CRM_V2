export const OPCOES_FORMA_PAGAMENTO = [
    'A VISTA',
    '7DDL',
    '14DDL',
    '21DDL',
    '28DDL',
    '28/35/42DDL',
    '28/42/56DDL',
    '30/45/60DDL',
    'Outros',
];

export const ROTULOS_STATUS_ORCAMENTO = {
    pendente: 'Pendente',
    aprovado: 'Aprovado',
    rejeitado: 'Rejeitado',
};

export const TONS_STATUS_ORCAMENTO = {
    pendente: 'warn',
    aprovado: 'ok',
    rejeitado: 'danger',
};

export const ROTULOS_NIVEL_APROVACAO = {
    nenhum: 'Nenhum',
    supervisor: 'Supervisor',
    diretor: 'Diretor',
};

export const ROTULOS_VALIDADE_ORCAMENTO = {
    vencido: 'Vencido',
    proximo: 'Vence em breve',
    no_prazo: 'No prazo',
    sem_validade: 'Sem validade',
};

export const TONS_VALIDADE_ORCAMENTO = {
    vencido: 'danger',
    proximo: 'warn',
    no_prazo: 'ok',
    sem_validade: 'neutral',
};

export const ROTULOS_TIPO_PRODUTO_SERVICO = {
    produto: 'Produto (IPI 3,25%)',
    servico: 'Serviço (sem IPI)',
};

export const ROTULOS_TIPO_FRETE = {
    CIF: 'CIF (frete por conta da Autopel)',
    FOB: 'FOB (frete por conta do cliente)',
};
