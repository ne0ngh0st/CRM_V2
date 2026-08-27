<?php

namespace App\Services\Legado;

use PDO;
use RuntimeException;

class LegadoConexao
{
    /**
     * Abre uma conexão PDO pro espelho local (autopel01_homolog, padrão) ou pra produção
     * (KingHost, só quando --fonte=producao for passado e as env vars _PRODUCAO_ existirem
     * temporariamente no .env — nunca deixar credencial de produção parada no arquivo).
     *
     * ⚠️ Lê de `config/legado.php`, NUNCA de `env()` direto: com `config:cache` ativo
     * (obrigatório em produção) o `.env` não é carregado e `env()` devolveria null,
     * montando um DSN vazio que só falharia na primeira sincronização real.
     */
    public static function pdo(string $fonte = 'homolog'): PDO
    {
        $conf = config("legado.{$fonte}");

        if (! is_array($conf)) {
            throw new RuntimeException("Fonte de legado desconhecida: '{$fonte}'. Use 'homolog' ou 'producao'.");
        }

        // Falha cedo e com mensagem útil. Sem isto, config faltando vira um DSN vazio
        // e um "SQLSTATE[HY000] [2002]" genérico que não diz o que configurar.
        if (empty($conf['host']) || empty($conf['database'])) {
            throw new RuntimeException(
                "Conexão de legado '{$fonte}' não configurada: defina LEGADO_DB_HOST e LEGADO_DB_DATABASE ".
                'no .env (ou as variáveis LEGADO_DB_PRODUCAO_* para a fonte de produção).'
            );
        }

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $conf['host'],
                $conf['port'] ?: 3306,
                $conf['database'],
            ),
            $conf['username'],
            $conf['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
