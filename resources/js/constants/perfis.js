
export const ROTULOS_PERFIL = {
    admin: 'Admin',
    diretor: 'Diretor',
    supervisor: 'Supervisor',
    vendedor: 'Vendedor',
    representante: 'Representante',
    venda_interna: 'Venda interna',
    assistente: 'Assistente',
};

/**
 * Perfis que atendem carteira própria (ligam, agendam, orçam a partir da Carteira).
 * Espelho de `User::PERFIS_CARTEIRA` no servidor — mudou lá, muda aqui.
 */
export const PERFIS_CARTEIRA = ['vendedor', 'representante', 'venda_interna'];

/**
 * Perfis que enxergam e atuam na carteira do PRÓPRIO código: os de cima mais o assistente,
 * que tem o código do supervisor que apoia. Espelho de `User::PERFIS_ESCOPO_PROPRIO`.
 *
 * ⚠️ Não trocar `PERFIS_CARTEIRA` por esta onde a pergunta é "quem vende" — o assistente
 * não entra em ranking nem em dropdown de vendedores.
 */
export const PERFIS_ESCOPO_PROPRIO = [...PERFIS_CARTEIRA, 'assistente'];
