<?php

/*
 * Configuração de performance — ver docs/performance.md e a Regra de ouro nº 9.
 *
 * ⚠️ Este arquivo é a FONTE ÚNICA (Regra de ouro nº 8) da lista de rotas medidas e
 * dos tetos aceitáveis. É lido tanto pelo comando `perf:baseline` quanto pelos testes
 * em tests/Feature/Performance/. Se a lista existisse nos dois, um teste passaria a
 * cobrir uma rota que o comando não mede (ou o contrário) sem ninguém perceber.
 */

return [

    /*
     * Orçamento por página. Contagem de query e tamanho de payload são DETERMINÍSTICOS:
     * valem igual no Docker local e na AWS, porque não dependem de hardware nem de volume
     * de dados. Por isso são eles — e não milissegundos — que travam regressão em teste.
     *
     * Milissegundos NÃO entram aqui de propósito: um teste de ms rodando contra o banco
     * vazio de `palma_v2_test` daria "ok" sempre, que é exatamente a confiança falsa que
     * a Regra de ouro nº 6 proíbe. Latência se valida no loadtest e no ALB.
     */
    'orcamento' => [
        'queries_max' => 40,
        'payload_kb_max' => 250,
    ],

    /*
     * Tetos temporários por rota — DÍVIDA DECLARADA, não permissão permanente.
     *
     * Uma rota entra aqui quando já está acima do teto global e a correção pertence a
     * uma fase futura do plano. O número é o que ela faz HOJE (mais uma folga mínima):
     * a rota continua travada contra piorar, e o item some daqui quando a fase concluir.
     *
     * A alternativa seria deixar o teste vermelho até lá — o que, na prática, faz a suíte
     * inteira virar ruído e ninguém mais reparar numa regressão de verdade.
     */
    'orcamento_por_rota' => [
        /*
         * Dashboard: 42 queries no caminho SEM cache. Sai daqui na Fase 4 (props viram
         * closures + Inertia::defer), meta ~15.
         *
         * ⚠️ Atenção ao comparar com o `perf:baseline`: o phpunit.xml força
         * CACHE_STORE=array, então o teste mede sempre o caminho FRIO. O baseline com
         * cache quente mostra 29 para a mesma página — números de cenários diferentes,
         * não dá pra usar um para calibrar o outro.
         */
        'dashboard' => ['queries_max' => 45],
    ],

    /*
     * Rotas percorridas pelo baseline, todas GET (o comando nunca dispara escrita).
     *
     * 'perfis' restringe quem consegue abrir a rota — sem isso a medição de uma página
     * de gestor com usuário vendedor mediria a página de erro 403, não a página real.
     * Ausente = todos os perfis com acesso.
     */
    'rotas_core' => [

        /*
         * 'partial' = props que a variante de recarga parcial vai pedir. Serve de régua
         * do progresso: HOJE as props do Dashboard são valores materializados, então o
         * partial economiza payload mas não economiza query nenhuma. Depois da Fase 4
         * (props viram closures + defer), a contagem de queries do partial deve despencar.
         * É essa diferença que prova que o defer funcionou.
         */
        'dashboard' => [
            'rota' => 'dashboard',
            'partial' => ['metaGauge', 'carteiraSegmento', 'faturamentoComparacao', 'pedidosAtencao'],
        ],

        // A Carteira é medida em três variantes porque elas exercitam caminhos
        // diferentes: a listagem simples, a ordenação que força LEFT JOIN + filesort
        // (medida em 510-655ms no escopo admin), e a aba que dispara os agendamentos.
        'carteira' => ['rota' => 'carteira.index', 'partial' => ['clientes']],
        'carteira:ordenada' => ['rota' => 'carteira.index', 'params' => ['ordenar' => 'segmento_asc']],
        'carteira:calendario' => ['rota' => 'carteira.index', 'params' => ['aba' => 'calendario']],

        'leads' => ['rota' => 'leads.index'],
        'pedidos-abertos' => ['rota' => 'pedidos.index'],
        'pedidos-emitidos' => ['rota' => 'pedidos.emitidos'],
        'orcamentos' => ['rota' => 'orcamentos.index'],
        'tabela-precos' => ['rota' => 'tabela-precos.index'],
        'catalogo-facas' => ['rota' => 'catalogo-facas.index'],

        // Cadastros pagina QUATRO datasets na mesma request hoje (bobinas, etiquetas,
        // clientes, leads) com só uma aba visível — é o maior alvo da Fase 6.
        'cadastros' => ['rota' => 'cadastros.index'],

        'equipe' => ['rota' => 'equipe.index', 'perfis' => ['admin', 'diretor', 'supervisor']],
        'metas' => ['rota' => 'metas.index', 'perfis' => ['admin', 'diretor', 'supervisor']],
        'visao-gestor' => ['rota' => 'visao-gestor.index', 'perfis' => ['admin', 'diretor', 'supervisor']],
    ],

    /*
     * Cache de agregação (Fases 2 e 3).
     *
     * O warming roda a cada 10 min e o TTL é 30 min — margem de 3x, para que uma rodada
     * perdida do worker não deixe a chave expirar na cara de um usuário. Ver §1.3 do
     * docs/performance.md: o problema nunca foi o cache, foi o cache FRIO.
     */
    'ttl_agregacao_minutos' => 30,
    'ttl_lookup_minutos' => 360,

    /*
     * Teto de profundidade da paginação.
     *
     * ⚠️ O motivo NÃO é o custo do OFFSET crescer aos poucos — foi o que eu supus, e o
     * EXPLAIN desmentiu. É uma TROCA DE PLANO do otimizador do MySQL, e ela é um
     * penhasco, não uma rampa. Medido em produção, com os 91.293 clientes do escopo
     * admin, ordenando por `razao_social`:
     *
     *   página 30 (offset  870) → type=index, usa clientes_razao_social_index,
     *                             lê    900 linhas →   96 ms
     *   página 37 (offset 1080) → ainda pelo índice, lê 1.110 linhas
     *   página 40 (offset 1170) → type=ALL, key=NULL, Using filesort,
     *                             lê 90.770 linhas → 1.084 ms
     *
     * Passado esse ponto o otimizador conclui que varrer a tabela inteira e ordenar sai
     * mais barato que caminhar o índice — e o tempo salta 10x de uma página para a outra.
     * Sem teto, a última página (3.044, alcançável num clique) custava 2,5 s, acima do
     * limite de 2 s que a Regra de ouro nº 9 manda tornar assíncrono.
     *
     * 30 páginas × 30 por página = 900 registros, todos abaixo de 150 ms medidos, com
     * margem confortável até a virada. Um vendedor com 283 clientes tem 10 páginas e
     * nunca encosta nisto; quem precisa ir além usa busca ou filtro, que seguem baratos
     * (138-244 ms).
     *
     * ⚠️ O ponto da virada depende do tamanho da tabela e das estatísticas do MySQL,
     * então ele se move. A margem existe para absorver isso — mas se `clientes` crescer
     * muito, vale refazer o EXPLAIN em vez de confiar neste número.
     *
     * ⚠️ É um TETO, não uma correção. A correção seria paginação por cursor (keyset), que
     * não tem esse comportamento — mas ela remove a navegação por número de página.
     */
    'max_paginas' => 30,

    /*
     * Quais escopos o job de warming aquece.
     *
     * ⚠️ 'vendedores' => false é DELIBERADO, não esquecimento. São ~200 vendedores e o
     * escopo de cada um custa 6-9 ms (medido) porque já é naturalmente seletivo. Aquecer
     * isso multiplicaria o trabalho do worker por 10 para economizar 9 ms por usuário.
     * O que dói é o escopo amplo (admin/diretor sem filtro, ~2.000 ms) e o de supervisor.
     */
    'escopos_aquecidos' => [
        'empresa' => true,
        'supervisores' => true,
        'vendedores' => false,
    ],

];
