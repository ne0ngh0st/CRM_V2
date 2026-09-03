<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covering index em `pedidos`, para a agregação de VENDA do painel.
 *
 * Replica o que a `2026_08_31_090000` provou valer 6-8x em `faturamentos`: o que decide
 * não é ter índice na coluna de data, é o índice COBRIR a coluna somada. Sem `valor_total`
 * dentro dele, o MySQL teria que ir à tabela linha a linha para somar, e conclui que
 * varrer tudo sai mais barato.
 *
 * ⚠️ SEJA HONESTO SOBRE O GANHO DE HOJE: `pedidos` tem 15.991 linhas e a agregação da
 * empresa inteira já roda em 16,5 ms — mesmo com `type=ALL`. Este índice não está aqui
 * pelo ganho atual; está pelo que vem. O histórico de pedidos emitidos ainda não foi
 * carregado (o legado tem 407.604 linhas em `pedidos_status`), e quando entrar, esta
 * agregação vira exatamente o caso que custou 4.074 ms em `faturamentos` antes do
 * covering index. Criar depois, com a tabela cheia, custaria uma janela de manutenção.
 *
 * ⚠️ 100% das linhas atuais são de 2026, então o `BETWEEN` do ano não corta NADA — é a
 * mesma armadilha documentada na Regra de ouro nº 6: índice só ajuda quando a condição
 * corta uma fração real da tabela, e aqui quem paga a conta é a cobertura, não o filtro.
 *
 * Os índices removidos viram prefixo dos novos — índice a mais custa escrita e RAM sem
 * pagar leitura, mesmo tratamento da `2026_08_31_110000`.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const NOVOS = [
        'ped_data_valor_idx' => '(data_pedido, valor_total)',
        'ped_vend_data_valor_idx' => '(cod_vendedor, data_pedido, valor_total)',
    ];

    /** @var array<string> */
    private const REDUNDANTES = [
        'pedidos_data_pedido_index',
        'pedidos_cod_vendedor_index',
        'pedidos_cod_vendedor_data_pedido_index',
    ];

    public function up(): void
    {
        // ⚠️ Criar ANTES de dropar: nunca deixar a tabela sem índice que sustente as
        // consultas em voo. Mesma ordem da migration de ligacoes (2026_09_02_100000).
        foreach (self::NOVOS as $nome => $colunas) {
            $this->criarSeFaltar($nome, $colunas);
        }

        foreach (self::REDUNDANTES as $nome) {
            $this->droparSeExistir($nome);
        }
    }

    public function down(): void
    {
        $this->criarSeFaltar('pedidos_data_pedido_index', '(data_pedido)');
        $this->criarSeFaltar('pedidos_cod_vendedor_index', '(cod_vendedor)');
        $this->criarSeFaltar('pedidos_cod_vendedor_data_pedido_index', '(cod_vendedor, data_pedido)');

        foreach (array_keys(self::NOVOS) as $nome) {
            $this->droparSeExistir($nome);
        }
    }

    /** Dev e produção podem chegar aqui em estados diferentes. */
    private function criarSeFaltar(string $indice, string $colunas): void
    {
        if (! $this->existe($indice)) {
            DB::statement("ALTER TABLE pedidos ADD INDEX `{$indice}` {$colunas}");
        }
    }

    private function droparSeExistir(string $indice): void
    {
        if ($this->existe($indice)) {
            DB::statement("ALTER TABLE pedidos DROP INDEX `{$indice}`");
        }
    }

    private function existe(string $indice): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['pedidos', $indice],
        ) !== null;
    }
};
