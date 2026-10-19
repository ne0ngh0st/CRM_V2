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

/*
 * Coluna/filtro "Margem": é o mesmo `status_gestor`, com o nível na frase quando pendente
 * ("Aguarda diretor"). O nome diz o que a aprovação do gestor é — aprovação de desconto —
 * para não ser confundida com o aceite do cliente.
 */
export function rotuloMargem(status, nivel) {
    if (status === 'pendente') {
        return nivel === 'diretor' ? 'Aguarda diretor' : 'Aguarda supervisor';
    }
    if (status === 'aprovado') {
        return nivel === 'nenhum' ? 'Aprovada (automática)' : 'Aprovada';
    }

    return 'Rejeitada';
}

export const ROTULOS_MARGEM_FILTRO = {
    pendente: 'Pendente',
    aprovado: 'Aprovada',
    rejeitado: 'Rejeitada',
};

// Aceite do cliente (o rótulo da linha vem pronto do servidor). Tom por `resposta_cliente` (null = aguardando).
export function tomRespostaCliente(resposta) {
    if (resposta === 'aprovado') return 'ok';
    if (resposta === 'recusado') return 'danger';

    return 'neutral';
}

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

// Tipo de venda → `invoiceType` do Portal e, no Protheus, a TES (tributação da NF-e).
// As chaves são as de Orcamento::TIPOS_VENDA; mudou lá, muda aqui.
export const ROTULOS_TIPO_VENDA = {
    consumo: 'Venda (consumo)',
    revenda: 'Venda (revenda)',
    servico: 'Serviço',
};

export const ROTULOS_TIPO_FRETE = {
    CIF: 'CIF (frete por conta da Autopel)',
    FOB: 'FOB (frete por conta do cliente)',
};
