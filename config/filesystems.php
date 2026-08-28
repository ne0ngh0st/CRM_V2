<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Discos lógicos da aplicação
    |--------------------------------------------------------------------------
    |
    | Em vez de espalhar `Storage::disk('public')` pelo código, cada TIPO de arquivo
    | aponta para um nome configurável. Assim o mesmo código funciona em dev (disco
    | local) e em produção (S3) sem `if` nenhum.
    |
    | ⚠️ Isto não é abstração por gosto: com dois app nodes atrás do ALB, arquivo
    | gravado no disco local do nó A não existe no nó B — a imagem quebra em ~50% dos
    | carregamentos e o link de download da planilha falha metade das vezes.
    |
    | uploads → conteúdo servido ao navegador (foto de perfil, imagem de faca).
    |           Precisa ser público.
    | exports → planilhas geradas em segundo plano. NUNCA público: o download passa
    |           pelo ExportacaoController, que confere se o arquivo é de quem pediu.
    |
    */

    'uploads' => env('UPLOADS_DISK', 'public'),

    'exports' => env('EXPORTS_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
