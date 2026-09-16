<?php

namespace App\Services\PowerBi;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * O schema do Power BI no MySQL: nome, existência e o que o usuário de leitura enxerga.
 *
 * Único lugar que sabe o nome do schema (`config('powerbi.schema')`) e o nome do banco
 * do app como as views o veem. As views vivem em `bi`, então uma tabela do app sem
 * qualificação seria procurada em `bi` — toda referência ao app tem que levar o nome
 * do banco, e esse nome muda entre dev (`palma_v2`) e suíte (`palma_v2_test`).
 */
class SchemaBi
{
    /**
     * Tabelas do APP que as views leem.
     *
     * ⚠️ É a lista de GRANT SELECT do `bi_leitura` (as views são `SQL SECURITY
     * INVOKER`: quem consulta precisa de permissão nas tabelas de baixo). O script
     * `infra/bi/criar-schema-e-usuario.sh` repete esta lista, e o
     * `SchemaBiInfraTest` falha se as duas divergirem — view nova lendo tabela nova
     * sem o grant daria "SELECT command denied" só no primeiro refresh do Power BI.
     */
    public const TABELAS_DO_APP = [
        'clientes',
        'faturamentos',
        'grupos_cliente',
        'leads',
        'metas_mensais',
        'model_has_roles',
        'orcamentos',
        'pedido_itens',
        'pedidos',
        'produtos',
        'roles',
        'segmentos',
        'users',
        'vendedor_perfis',
        'vendedores_totvs',
    ];

    public static function nome(): string
    {
        $nome = (string) config('powerbi.schema');

        // Vira identificador cru em DDL: só letras, dígitos e sublinhado.
        if (preg_match('/^[A-Za-z0-9_]+$/', $nome) !== 1) {
            throw new RuntimeException("Nome de schema do BI inválido: '{$nome}'.");
        }

        return $nome;
    }

    /** `bi`.`tabela` */
    public static function tabela(string $tabela): string
    {
        return '`'.self::nome().'`.`'.$tabela.'`';
    }

    /** `palma_v2`.`tabela` — como as views do BI enxergam uma tabela do app. */
    public static function app(string $tabela): string
    {
        return '`'.DB::connection()->getDatabaseName().'`.`'.$tabela.'`';
    }

    public static function existe(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [self::nome()],
        ) !== null;
    }

    /**
     * O schema é criado FORA das migrations, com a credencial master (ver o script em
     * `infra/bi/`): o usuário do app não tem, nem deve ter, `CREATE` global.
     */
    public static function exigir(): void
    {
        if (self::existe()) {
            return;
        }

        $nome = self::nome();

        throw new RuntimeException(
            "O schema '{$nome}' não existe neste MySQL. Crie-o antes de migrar:"
            ." produção → infra/bi/criar-schema-e-usuario.sh;"
            ." local → CREATE DATABASE {$nome} + GRANT ALL ON {$nome}.* TO 'palma'@'%' (ver docs/power-bi.md)."
        );
    }
}
