<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove os dois indices que a migration 090000 tornou redundantes.
 *
 * POR QUE UMA MIGRATION SEPARADA, e nao uma edicao da 090000: a 090000 ja tinha
 * rodado em producao quando os drops foram escritos. Migration ja aplicada nao roda
 * de novo -- o Laravel a marca no `migrations` e segue adiante --, entao a edicao
 * ficou sem efeito la e o banco de producao terminou com os quatro indices, dois
 * deles inuteis. Editar migration ja aplicada faz o arquivo mentir sobre o que o
 * banco tem; a correcao e sempre uma migration nova.
 *
 * POR QUE E SEGURO REMOVER: cada um e prefixo exato de um dos novos --
 * `(data_emissao)` de `(data_emissao, valor_total)`, e `(cod_vendedor, data_emissao)`
 * de `(cod_vendedor, data_emissao, valor_total)`. Um indice composto atende qualquer
 * consulta que use um prefixo a esquerda dele, entao nenhum caminho de acesso se
 * perde. Confirmado por EXPLAIN depois do drop: o plano continua `range` +
 * `Using index` nas duas agregacoes.
 *
 * GANHO MEDIDO sobre as 5,85 M de linhas reais: o indice da tabela cai de 624 MB
 * para 349 MB (-275 MB), e cada INSERT passa a manter dois indices em vez de quatro
 * -- o que pesa muito nos imports em lote: recarregar um ano com os quatro presentes
 * levou 10m40s, contra 66s com dois.
 *
 * ⚠️ IDEMPOTENTE de proposito. Em desenvolvimento estes indices ja foram removidos a
 * mao durante a investigacao, enquanto em producao ainda existem -- os dois ambientes
 * chegam aqui em estados diferentes, e um `dropIndex` cego falharia em um deles.
 */
return new class extends Migration
{
    private const REDUNDANTES = [
        'faturamentos_data_emissao_index',
        'faturamentos_cod_vendedor_data_emissao_index',
    ];

    public function up(): void
    {
        foreach (self::REDUNDANTES as $indice) {
            if (! $this->indiceExiste($indice)) {
                continue;
            }

            Schema::table('faturamentos', function (Blueprint $table) use ($indice) {
                $table->dropIndex($indice);
            });
        }
    }

    public function down(): void
    {
        if (! $this->indiceExiste('faturamentos_data_emissao_index')) {
            Schema::table('faturamentos', function (Blueprint $table) {
                $table->index('data_emissao', 'faturamentos_data_emissao_index');
            });
        }

        if (! $this->indiceExiste('faturamentos_cod_vendedor_data_emissao_index')) {
            Schema::table('faturamentos', function (Blueprint $table) {
                $table->index(['cod_vendedor', 'data_emissao'], 'faturamentos_cod_vendedor_data_emissao_index');
            });
        }
    }

    private function indiceExiste(string $indice): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'faturamentos')
            ->where('index_name', $indice)
            ->exists();
    }
};
