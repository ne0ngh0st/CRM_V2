/**
 * Opções de `users.resumo_diario` — espelho do enum da migration
 * `2026_09_28_100000_add_resumo_diario_to_users` e da validação do
 * `EquipeController::update`. Mudou lá, muda aqui.
 */
export const OPCOES_RESUMO_DIARIO = [
    { valor: 'nenhum', rotulo: 'Não recebe', ajuda: 'Nenhum e-mail automático.' },
    {
        valor: 'equipe',
        rotulo: 'Resumo da própria equipe',
        ajuda: 'Quem tem este usuário como supervisor, mais a carteira dele. Para supervisores e diretores com equipe.',
    },
    {
        valor: 'consolidado',
        rotulo: 'Consolidado de todas as equipes',
        ajuda: 'Uma seção para cada gestor marcado com "Resumo da própria equipe", mais o total.',
    },
];
