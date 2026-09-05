<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Relatórios do TOTVS
    |---------------------------------------------------------------------------
    |
    | Onde ficam os CSVs exportados do TOTVS, do ponto de vista de quem LÊ. Dentro do
    | container isso é `/relatorios`, montado pelo docker-compose a partir de
    | TOTVS_RELATORIOS_PATH no .env (na máquina do Tony, a raiz "RELATORIOS TOTVS" no
    | OneDrive — a pasta INTEIRA, não só "CSV/": o Tony organiza cada relatório na sua
    | própria subpasta, então os caminhos de `arquivo` abaixo entram com o prefixo da
    | subpasta onde cada um vive hoje).
    |
    | ⚠️ Duas variáveis diferentes de propósito: TOTVS_RELATORIOS_PATH é o caminho no
    | HOST, e só o docker-compose usa; `diretorio` é o caminho DENTRO do container, que
    | é o que o PHP abre. Trocar um pelo outro dá "arquivo não encontrado" num caminho
    | que existe — só não daquele lado.
    |
    */

    'diretorio' => env('TOTVS_RELATORIOS_DIR', '/relatorios'),

    /*
    |---------------------------------------------------------------------------
    | Arquivos por domínio
    |---------------------------------------------------------------------------
    |
    | O nome do arquivo é escolha do Tony ao exportar, então mora aqui e não chumbado
    | no comando. `arquivo` aceita uma LISTA DE PADRÕES DE GLOB (aceitam `*`), tentados
    | todos, e vence o arquivo casado mais recente por data de modificação — não o
    | primeiro da lista. Duas coisas mudam de vez em quando e as duas têm que continuar
    | funcionando sem deploy: o NOME do export ("META VENDA" virou "pedidos_emitidos")
    | e, desde 03/09, o próprio nome carrega o MÊS ("Pedidos emitidos - 092026 -
    | SQL.csv") — nome fixo quebraria a cada início de mês.
    |
    | `periodo` diz como o import trata o que já existe:
    |
    |   'completo'  → o arquivo é o retrato inteiro; upsert, nunca apaga linha.
    |   'recorte'   → o arquivo traz só uma faixa de datas (o Tony gera o mês vigente).
    |                 O import apaga SÓ essa faixa e reinsere. Truncar apagaria os
    |                 meses anteriores, que não existem em mais lugar nenhum desde a
    |                 carga do histórico 2018-2025.
    |
    */

    'arquivos' => [

        'clientes' => [
            'arquivo' => ['CSV/Clientes - SQL.csv'],
            'rlt' => '210 - CADASTRO DE CLIENTES',
            'periodo' => 'completo',
        ],

        'ultimo_faturamento' => [
            'arquivo' => ['CSV/Ultimo faturamento - SQL.csv'],
            'rlt' => '199 - ULTIMO FATURAMENTO CLIENTE',
            'periodo' => 'completo',
            // É daqui que saem `segmentos` e `grupos_cliente`: o código mora em
            // CLIENTES, mas a descrição só existe neste relatório.
        ],

        'pedidos_abertos' => [
            'arquivo' => ['CSV/Pedidos abertos - SQL.csv'],
            'rlt' => '200 - PEDIDOS EM ABERTO COM STATUS',
            'periodo' => 'completo',
            // "Completo" aqui quer dizer "tudo que está em aberto agora" — pedido
            // faturado sai do relatório sozinho, não é recorte de data.
        ],

        'faturamento' => [
            'arquivo' => ['CSV/FAT - SQL.csv'],
            'rlt' => '198 - FATURAMENTO EQUIPE',
            'periodo' => 'recorte',
            'coluna_data' => 'EMISSAO',
        ],

        'pedidos_emitidos' => [
            'arquivo' => [
                // Convenção atual (desde 03/09): pasta própria, mês no nome.
                'Pedidos emitidos/Pedidos emitidos*SQL.csv',
                'Pedidos emitidos/pedidos_emitidos*SQL.csv',
                // Convenções anteriores, mantidas como fallback — não fazem mal
                // enquanto nada com esse nome existir na pasta.
                'CSV/pedidos_emitidos - SQL.csv',
                'CSV/Pedidos emitidos - SQL.csv',

                // ⚠️ `CSV/META VENDA - SQL.csv` FOI REMOVIDO desta lista em 2026-09-05, e
                // não deve voltar. O nome antigo continua sendo gerado de vez em quando e
                // o arquivo cobre agosto+setembro — exatamente o mesmo período dos
                // `Pedidos emitidos - 082026/092026` da pasta nova. Como este domínio
                // processa TODOS os arquivos que casarem (não só o mais recente), mantê-lo
                // significava reler e regravar ~170 mil linhas a cada importação, sem
                // acrescentar um pedido sequer.
                //
                // Enquanto ele teve o formato velho de 23 colunas, o `exigirColunas` o
                // pulava sozinho e ninguém notava; regerado com as 32 colunas atuais ele
                // passaria a ser importado de verdade. Tirar daqui resolve sem depender de
                // alguém lembrar de não gerar o arquivo.
            ],
            'rlt' => '232 - CONSULTA DE PEDIDOS EMITIDOS META DE VENDAS',
            'periodo' => 'recorte',
            'coluna_data' => 'DT_EMISSAO',
        ],

        'leads' => [
            'arquivo' => ['CSV/base_marco - SQL.csv'],
            'rlt' => null, // não é relatório do TOTVS: é a base de prospecção (ABRAS)
            'periodo' => 'completo',
        ],

        /*
         * `produtos` ainda não entra: hoje o Tony mescla DOIS CSVs fora do sistema para
         * montar essa base, e o `PRODUTOS - SQL.xlsx` da pasta está vazio. Antes de
         * automatizar, a query de origem precisa virar uma só. Até lá, produto continua
         * vindo pelo `legado:import-produtos` (espelho do v1).
         */

    ],

];
