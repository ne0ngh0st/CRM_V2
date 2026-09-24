
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
