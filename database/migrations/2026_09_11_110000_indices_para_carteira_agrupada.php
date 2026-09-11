<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Torna viável agrupar a Carteira por `cod_cliente` (uma linha por cliente, filiais
 * na linha expansível) sem estourar o orçamento de 400 ms da Regra de ouro nº 9.
 *
 * A listagem agrupada roda em três passagens (ver `CarteiraController::linhasAgrupadas()`):
 * a primeira descobre QUAIS 30 clientes entram na página (`GROUP BY cod_cliente` +
 * `ORDER BY <agregado>`), a segunda agrega filiais/entregas/datas desses 30, a terceira
 * busca a linha da filial-âncora. Estes dois índices servem as duas primeiras.
 *
 * ⚠️ Sem eles a tela é inviável para quem vê a empresa inteira. Medido em dev com
 * 92.209 linhas / 39.692 clientes, INTERCALADO via `IGNORE INDEX` (em Docker/WSL2 o
 * mesmo SELECT varia 30% entre execuções, então medir "antes" e "depois" em sequência
 * mede ruído — lição de 2026-09-04):
 *
 *   passo 1, ordem por última compra   429 ms -> 202 ms   (mediana de 5 rodadas)
 *   passo 1, ordem por nome (a PADRÃO) 390 ms -> 113 ms
 *   passo 2, pior caso (30 maiores)    340 ms ->  81 ms
 *
 * Página completa, com os dois índices: admin sem filtro 362 ms, vendedor grande
 * (14.304 linhas) 280 ms, vendedor típico 30 ms.
 *
 * ⚠️ `Extra:` continua com `Using temporary; Using filesort` mesmo com o índice — e
 * isso é esperado, não defeito: agrupar 39.692 clientes e ordenar pelo AGREGADO exige
 * materializar os grupos. O que o índice compra é `Using index` (não vai mais à tabela
 * linha a linha), e é daí que vêm os 2x-4x. Mesma distinção de 2026-08-31: índice
 * escolhido ≠ índice suficiente, quem denuncia é o `Extra:`.
 *
 * ⚠️ CUSTAM 9,1 MB em disco (índices da tabela: 47,7 -> 56,8 MB), e isso não é
 * irrelevante aqui: em 2026-08-31 o RDS de produção ficou com ~90 MB de
 * `FreeableMemory` depois da carga do histórico de faturamento. Não é bloqueante, mas
 * conta na decisão de subir para `db.t4g.medium` — ver a Regra de ouro nº 9.
 *
 * ⚠️ Um terceiro índice, `(cod_cliente, data_ultima_compra)`, foi testado e DESCARTADO:
 * o de agrupamento já o contém como prefixo funcional e a diferença (142 ms contra
 * 160 ms no passo 1) ficou dentro do ruído. Não recriar achando que falta.
 *
 * Idempotente porque dev e produção podem chegar aqui em estados diferentes — mesmo
 * cuidado das migrations `2026_08_31_110000` e `2026_09_02_100000`.
 */
return new class extends Migration
{
    /** [nome, colunas] */
    private const INDICES = [
        /*
         * Cobre o passo 2 inteiro (contagem de filiais, contagem de entregas, MAX das
         * duas datas e a escolha da âncora por MIN(loja)) sem tocar a tabela, e serve
         * de covering também ao passo 1 quando a ordem é por data.
         */
        ['clientes_agrupamento_index', ['cod_cliente', 'loja', 'data_ultima_compra', 'data_ultimo_contato']],

        /*
         * `razao_social` logo após `cod_cliente` é o que torna `MIN(razao_social)` por
         * grupo barato. Existe separado porque esta é a ordenação PADRÃO da tela — o
         * caminho mais percorrido —, e no índice de agrupamento acima a razão social
         * entraria tarde demais para ajudar.
         */
        ['clientes_cod_cliente_razao_social_index', ['cod_cliente', 'razao_social']],
    ];

    public function up(): void
    {
        foreach (self::INDICES as [$nome, $colunas]) {
            if ($this->existe($nome)) {
                continue;
            }

            $lista = implode(', ', $colunas);
            DB::statement("CREATE INDEX {$nome} ON clientes ({$lista})");
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as [$nome, ]) {
            if ($this->existe($nome)) {
                DB::statement("DROP INDEX {$nome} ON clientes");
            }
        }
    }

    private function existe(string $nome): bool
    {
        return DB::select('SHOW INDEX FROM clientes WHERE Key_name = ?', [$nome]) !== [];
    }
};
