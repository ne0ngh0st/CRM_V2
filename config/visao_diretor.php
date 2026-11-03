<?php

return [
    /*
     * Interruptor da seção Visão Diretor (menu e rotas). Desligada, o menu some e as rotas
     * respondem 404. Dados e agendamentos seguem rodando, para a volta ser só um `.env`.
     *
     * ⚠️ NÃO mexe no gate `ver-visao-diretor`: ele também decide quem marca a estrela de
     * especialista no quadro de Segmentos, que continua valendo.
     */
    'habilitada' => (bool) env('VISAO_DIRETOR_HABILITADA', false),
];
