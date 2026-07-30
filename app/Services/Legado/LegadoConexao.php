<?php

namespace App\Services\Legado;

use PDO;

class LegadoConexao
{
    /**
     * Abre uma conexão PDO pro espelho local (autopel01_homolog, padrão) ou pra produção
     * (KingHost, só quando --fonte=producao for passado e as env vars _PRODUCAO_ existirem
     * temporariamente no .env — nunca deixar credencial de produção parada no arquivo).
     */
    public static function pdo(string $fonte = 'homolog'): PDO
    {
        $prefixo = $fonte === 'producao' ? 'LEGADO_DB_PRODUCAO_' : 'LEGADO_DB_';

        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env($prefixo.'HOST'), env($prefixo.'DATABASE')),
            env($prefixo.'USERNAME'),
            env($prefixo.'PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
