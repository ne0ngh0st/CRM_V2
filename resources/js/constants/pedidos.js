/*
 * A COR de cada etapa do pedido no TOTVS.
 *
 * ⚠️ O RÓTULO NÃO MORA MAIS AQUI. Ele vem pronto do servidor, em `pedido.statusRotulo`,
 * de `App\Services\Pedidos\StatusPedidoResolver` — que é também quem decide quais status
 * existem. Enquanto o mapa de rótulos era duplicado entre PHP e JS, a cópia do front
 * ficou para trás pelo menos uma vez: `Detalhes.vue` chegou a exibir a string crua
 * `pendente_totvs` porque não conhecia um valor novo do enum (Regra de ouro nº 8).
 *
 * A cor fica no front porque é decisão de front, e a leitura é simples: VERMELHO é o
 * pedido travado, que precisa de alguém; o resto é o processo andando. Verde só quando
 * já saiu da fábrica.
 *
 * ⚠️ Status sem entrada aqui cai em 'neutral' na tela, nunca quebra — mas se um status
 * novo for criado no resolver, é aqui que ele ganha cor.
 */
export const TONS_STATUS_PEDIDO = {
    bloqueio_estoque: 'danger',
    bloqueio_credito: 'danger',
    rejeicao_credito: 'danger',
    bloqueio_arte: 'danger',
    liberado: 'neutral',
    em_carga: 'neutral',
    separacao: 'neutral',
    separado: 'ok',
    faturando: 'ok',
    faturado: 'ok',
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
