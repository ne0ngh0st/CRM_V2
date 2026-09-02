/*
 * Canais de contato. Espelha `App\Models\Ligacao::TIPOS_CONTATO` — canal novo entra
 * na constante do PHP (validação), no enum da coluna e aqui (rótulo exibido).
 *
 * A ORDEM desta lista é a ordem em que os canais aparecem nos KPIs do Painel e nas
 * colunas da Visão do Gestor. Está declarada uma vez para as duas telas não
 * divergirem sozinhas (Regra de ouro nº 8).
 */
export const CANAIS_CONTATO = ['telefonica', 'whatsapp', 'email', 'presencial'];

export const ROTULOS_CANAL_CONTATO = {
    telefonica: 'Telefone',
    whatsapp: 'WhatsApp',
    email: 'E-mail',
    presencial: 'Presencial',
};

/** Rótulo curto, para caber em cabeçalho de coluna e em tile de KPI. */
export const ROTULOS_CANAL_CURTO = {
    telefonica: 'Tel.',
    whatsapp: 'Whats',
    email: 'E-mail',
    presencial: 'Presen.',
};
