<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Relatórios do TOTVS
    |---------------------------------------------------------------------------
    |
    | Onde ficam os CSVs exportados do TOTVS, do ponto de vista de quem LÊ. Dentro
    | do container isso é `/relatorios`, montado pelo docker-compose a partir de
    | TOTVS_RELATORIOS_PATH no .env (na máquina do Tony, a pasta
    | "RELATORIOS TOTVS/CSV" no OneDrive).
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
    | no comando. `periodo` diz como o import trata o que já existe:
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
            'arquivo' => 'Clientes - SQL.csv',
            'rlt' => '210 - CADASTRO DE CLIENTES',
            'periodo' => 'completo',
        ],

        'ultimo_faturamento' => [
            'arquivo' => 'Ultimo faturamento - SQL.csv',
            'rlt' => '199 - ULTIMO FATURAMENTO CLIENTE',
            'periodo' => 'completo',
            // É daqui que saem `segmentos` e `grupos_cliente`: o código mora em
            // CLIENTES, mas a descrição só existe neste relatório.
        ],

        'pedidos_abertos' => [
            'arquivo' => 'Pedidos abertos - SQL.csv',
            'rlt' => '200 - PEDIDOS EM ABERTO COM STATUS',
            'periodo' => 'completo',
            // "Completo" aqui quer dizer "tudo que está em aberto agora" — pedido
            // faturado sai do relatório sozinho, não é recorte de data.
        ],

        'faturamento' => [
            'arquivo' => 'FAT - SQL.csv',
            'rlt' => '198 - FATURAMENTO EQUIPE',
            'periodo' => 'recorte',
            'coluna_data' => 'EMISSAO',
        ],

        'pedidos_emitidos' => [
            'arquivo' => 'META VENDA - SQL.csv',
            'rlt' => '232 - CONSULTA DE PEDIDOS EMITIDOS META DE VENDAS',
            'periodo' => 'recorte',
            'coluna_data' => 'DT_EMISSAO',
        ],

        'leads' => [
            'arquivo' => 'base_marco - SQL.csv',
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
