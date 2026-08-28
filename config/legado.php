<?php

/*
 * Conexões com o banco do PALMA legado, lidas pelos comandos `legado:import-*`.
 *
 * ⚠️ Este arquivo existe porque `env()` NÃO pode ser chamado fora de `config/`.
 * Com `config:cache` ativo (obrigatório em produção, ver docs/performance.md), o
 * Laravel nem carrega o `.env` — todo `env()` espalhado pelo código passa a devolver
 * o default, silenciosamente. Antes disso, o `LegadoConexao` montava o DSN com
 * `env()` direto e teria montado `mysql:host=;port=3306;dbname=` em produção, com
 * erro de conexão só na primeira sincronização.
 *
 * ⚠️ Estas são conexões PDO cruas, de LEITURA, e de propósito NÃO ficam em
 * `config/database.php`: o legado é fonte, nunca destino (Regra de ouro nº 7).
 * Deixá-las fora do array de conexões do Eloquent evita que qualquer migration,
 * seeder ou `--database=` aponte para lá por acidente.
 */

return [

    /*
     * Espelho local (autopel01_homolog). É o padrão de todos os comandos de import.
     */
    'homolog' => [
        'host' => env('LEGADO_DB_HOST'),
        'port' => env('LEGADO_DB_PORT', 3306),
        'database' => env('LEGADO_DB_DATABASE'),
        'username' => env('LEGADO_DB_USERNAME'),
        'password' => env('LEGADO_DB_PASSWORD'),
    ],

    /*
     * Produção (KingHost). Só é usada com `--fonte=producao`, e as env vars
     * correspondentes devem existir apenas temporariamente no `.env` —
     * nunca deixar credencial de produção parada no arquivo.
     */
    'producao' => [
        'host' => env('LEGADO_DB_PRODUCAO_HOST'),
        'port' => env('LEGADO_DB_PRODUCAO_PORT', 3306),
        'database' => env('LEGADO_DB_PRODUCAO_DATABASE'),
        'username' => env('LEGADO_DB_PRODUCAO_USERNAME'),
        'password' => env('LEGADO_DB_PRODUCAO_PASSWORD'),
    ],

];
