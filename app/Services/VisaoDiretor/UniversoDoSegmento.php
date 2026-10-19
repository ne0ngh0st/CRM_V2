<?php

namespace App\Services\VisaoDiretor;

use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A tabela de cada segmento da Visão Diretor → Maiores por Segmento: onde a Autopel atua
 * (cliente ativo), deixou de atuar (perdendo / a trabalhar) e nunca atuou (lead).
 *
 * Redesenhada em 2026-10-14 (Tony): a página é para o dono da empresa, e redes, contas e
 * vínculos confundiam mais do que explicavam. Agora é o mesmo grão da Carteira:
 * - **cliente** = EMPRESA (raiz do CNPJ), numa aba só: a do segmento em que ela tem mais
 *   filiais (`criarSegmentoDaEmpresa()`, desde 2026-10-15). Filiais e status contam TODAS
 *   as filiais dela; o status é o da última compra, o mesmo corte da pill da Carteira.
 *   Empresas da mesma rede da Maiores redes viram uma linha só (`juntarPorRede()`).
 * - **lead** = lead visível do segmento (por nome), fora da etapa "Outros", cujo CNPJ NÃO
 *   é de cliente (CNPJ exato, a regra do import de leads).
 * A ordem é o capital social da Receita, que não aparece na tela.
 *
 * Lê a tabela `visao_diretor_universo`, que só `reconstruir()` escreve. O status é
 * calculado na consulta a partir da data, então não envelhece entre reconstruções; quem
 * envelhece é quem entra e sai — por isso a tela mostra quando foi reconstruída.
 */
class UniversoDoSegmento
{
    public const POR_PAGINA = 30;

    public function __construct(
        private readonly ClienteStatusResolver $statusResolver,
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {
    }

    /**
     * A empresa de cada filial, calculada na consulta: a raiz do CNPJ (8 dígitos) ou, sem
     * CNPJ de 14 dígitos (CPF, vazio), o próprio código do cliente.
     *
     * ⚠️ Regra de ouro nº 3: a raiz NUNCA vira coluna armazenada — é esta expressão, usada
     * só dentro de `reconstruir()`. Juntar por empresa foi pedido do Tony (2026-10-14:
     * "pesquisar Carrefour traz duas páginas de Carrefour").
     */
    /** Nome fantasia que é marcador interno do cadastro do TOTVS, não marca (regex, sem caixa). */
    private const FANTASIA_DE_CADASTRO = '^(ENTREGA|ALMOX|RECEBIMENTO|COBRAN|O MESMO|MATRIZ|FILIAL|DEP[OÓ]SITO|CD( |$|-)|LOJA [0-9])|BAIXAD|INAPT|INATIV';

    /**
     * Chave da empresa na query: os 8 primeiros dígitos do CNPJ, calculados — nunca uma
     * coluna (Regra de ouro nº 3). Pública porque a planilha de pesquisa do supermercadista
     * agrupa pela mesma chave.
     */
    public const EMPRESA = "CASE WHEN CHAR_LENGTH(c.cnpj_digitos) = 14 THEN LEFT(c.cnpj_digitos, 8) ELSE CONCAT('#', c.cod_cliente) END";

    /**
     * Refaz a tabela inteira. Tudo numa transação: quem lê no meio continua vendo a versão
     * anterior (REPEATABLE READ), nunca uma lista pela metade.
     *
     * @return array{clientes: int, leads: int}
     */
    public function reconstruir(): array
    {
        $agora = Carbon::now();
        $empresa = self::EMPRESA;
        $empresaA = str_replace('c.', 'a.', $empresa);

        return DB::transaction(function () use ($agora, $empresa, $empresaA) {
            DB::table('visao_diretor_universo')->delete();

            /*
             * Uma linha por EMPRESA, no segmento DELA (`criarSegmentoDaEmpresa()`): a empresa
             * aparece numa aba só. Filiais, capital e última compra contam TODAS as filiais da
             * empresa, de qualquer segmento — é a empresa inteira que a linha descreve.
             *
             * A âncora (`cliente_id`) é a matriz (/0001) quando existe — é dela o nome, o
             * CNPJ, a UF e o vendedor da linha. O nome fantasia vem no passo seguinte.
             *
             * ⚠️ EMPRESA FECHADA NÃO ENTRA (Tony, 2026-10-14): se TODAS as filiais dela
             * estão com situação conhecida e diferente de ATIVA na Receita (baixada,
             * inapta, suspensa…), a linha sai — mesmo que tenha comprado no último ano. Em
             * produção eram ~8,8 mil empresas, quase todas em "A trabalhar", e a lista de onde
             * voltar a vender virava lista de empresa fechada. Situação desconhecida (nunca
             * verificada, CPF) conta como ativa: não acusar o que não se sabe, como a Carteira.
             * Com a matriz baixada e alguma filial ativa, a âncora é a filial ativa.
             * Leads não precisam disso: o import já só deixa entrar lead ATIVA.
             * ⚠️ GROUP_CONCAT corta em 1.024 bytes por padrão e, num INSERT, o corte vira
             * ERRO (empresa com centenas de filiais). Daí o limite da sessão.
             */
            self::criarSegmentoDaEmpresa();

            DB::statement('SET SESSION group_concat_max_len = 4194304');
            DB::insert(<<<SQL
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, cod_cliente, cliente_id, nome, nome_fantasia, lojas,
                     data_ultima_compra, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT v.seg, 'cliente', MIN(c.cod_cliente),
                       SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY COALESCE(cs.situacao, 'ATIVA') <> 'ATIVA', SUBSTRING(c.cnpj_digitos, 9, 4) = '0001' DESC, c.id), ',', 1),
                       '', NULL,
                       COUNT(*),
                       MAX(c.data_ultima_compra), MAX(cs.capital_social), COALESCE(MAX(cs.capital_social), -1),
                       ELT(MAX(FIELD(cs.porte, 'ME', 'EPP', 'DEMAIS')), 'ME', 'EPP', 'DEMAIS'),
                       ?
                FROM clientes c
                JOIN vdu_segmento_empresa v ON v.empresa = {$empresa}
                LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
                GROUP BY v.empresa, v.seg
                HAVING SUM(COALESCE(cs.situacao, 'ATIVA') = 'ATIVA') > 0
                SQL, [$agora]);
            DB::statement('DROP TEMPORARY TABLE vdu_segmento_empresa');

            /*
             * O nome principal é a RAZÃO SOCIAL da âncora (a tela põe o fantasia em cima
             * quando houver). `cod_cliente` passa a ser o da âncora: é o que abre a ficha.
             */
            DB::update(<<<'SQL'
                UPDATE visao_diretor_universo u
                JOIN clientes a ON a.id = u.cliente_id
                SET u.nome = COALESCE(NULLIF(a.razao_social, ''), a.nome_fantasia, a.cod_cliente),
                    u.razao_social = a.razao_social,
                    u.cod_cliente = a.cod_cliente,
                    u.documento = a.cnpj,
                    u.uf = NULLIF(a.estado, ''),
                    u.cod_vendedor = NULLIF(a.cod_vendedor, '')
                WHERE u.tipo = 'cliente'
                SQL);

            /*
             * O nome fantasia da linha é a MARCA: o trecho antes do primeiro "-" ou "("
             * do fantasia de cada filial ("ATACADAO - LJ 1" → "ATACADAO"), o mais frequente
             * entre as filiais da empresa (empate: o mais curto).
             * Marcadores de uso interno do cadastro (ENTREGA, ALMOX, MATRIZ, CNPJ BAIXADO,
             * LOJA 01…) não contam: escolher o "mais curto" puro trazia justamente eles.
             * Se nenhum fantasia se repete (cada loja do Atacadão tem o seu, e o "vencedor"
             * era um endereço), não há marca: fica sem fantasia e a razão social sobe.
             *
             * ⚠️ Passa por uma tabela TEMPORÁRIA com chave: o JOIN direto contra a derivada
             * (casando pela expressão da empresa) virava laço aninhado sem índice e não
             * terminava em 5 minutos no dev.
             */
            DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_fantasia');
            DB::statement('CREATE TEMPORARY TABLE vdu_fantasia (empresa VARCHAR(30) PRIMARY KEY, f VARCHAR(255)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            DB::insert(<<<SQL
                INSERT INTO vdu_fantasia (empresa, f)
                SELECT empresa, f FROM (
                    SELECT empresa, MIN(marca) AS f, COUNT(*) AS vezes,
                           SUM(COUNT(*)) OVER (PARTITION BY empresa) AS filiais,
                           ROW_NUMBER() OVER (PARTITION BY empresa ORDER BY COUNT(*) DESC, CHAR_LENGTH(MIN(marca))) AS rn
                    FROM (
                        SELECT {$empresa} AS empresa,
                               TRIM(REGEXP_SUBSTR(TRIM(c.nome_fantasia), '^[^-(]+')) AS marca
                        FROM clientes c
                    ) b
                    WHERE marca <> '' AND NOT REGEXP_LIKE(marca, ?, 'i')
                    GROUP BY empresa, UPPER(marca)
                ) t WHERE rn = 1 AND (vezes >= 2 OR filiais = 1)
                SQL, [self::FANTASIA_DE_CADASTRO]);
            DB::update(<<<SQL
                UPDATE visao_diretor_universo u
                JOIN clientes a ON a.id = u.cliente_id
                JOIN vdu_fantasia m ON m.empresa = {$empresaA}
                SET u.nome_fantasia = m.f
                WHERE u.tipo = 'cliente'
                SQL);
            DB::statement('DROP TEMPORARY TABLE vdu_fantasia');

            $this->trocarSupermercadistaPeloRanking($agora);
            $this->juntarPorRede($agora);

            /*
             * ⚠️ O CNPJ do lead pode vir com máscara e sem o zero que o Excel come; os
             * dígitos são normalizados aqui como `Normalizador::digitosCnpj()` faz (12-13
             * dígitos → 14). Lead sem CNPJ (o aberto pela diretoria) entra: não há como
             * dizer que já é cliente.
             *
             * Lead de EMPRESA que já é cliente (mesma raiz) não entra: a empresa já está na
             * linha do cliente.
             *
             * ⚠️ As raízes dos clientes vão antes para uma tabela TEMPORÁRIA com chave, e o
             * lead consulta por igualdade. A primeira versão fazia
             * `cnpj_digitos LIKE CONCAT(LEFT(l.digitos, 8), '%')`: padrão montado em tempo de
             * execução não usa índice, e com os ~12 mil leads de produção virou 12 mil
             * varreduras de `clientes` — travou o deploy de 14/10 por 7+ minutos. No dev
             * passava porque lá nenhum lead tem segmento (Regra de ouro nº 6).
             */
            DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_raiz_cliente');
            DB::statement('CREATE TEMPORARY TABLE vdu_raiz_cliente (raiz CHAR(8) PRIMARY KEY) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            DB::statement(<<<'SQL'
                INSERT IGNORE INTO vdu_raiz_cliente (raiz)
                SELECT DISTINCT LEFT(cnpj_digitos, 8) FROM clientes WHERE CHAR_LENGTH(cnpj_digitos) = 14
                SQL);
            DB::insert(<<<'SQL'
                INSERT INTO visao_diretor_universo
                    (segmento_codigo, tipo, lead_id, nome, razao_social, nome_fantasia, documento, uf, cod_vendedor,
                     lojas, capital_social, capital_ordem, porte, reconstruido_em)
                SELECT s.codigo, 'lead', l.id, COALESCE(NULLIF(l.razao_social, ''), l.nome), l.razao_social,
                       NULLIF(l.nome, ''), l.cnpj, NULLIF(l.estado, ''),
                       NULLIF(l.cod_vendedor, ''), 1, cs.capital_social, COALESCE(cs.capital_social, -1), cs.porte, ?
                FROM (
                    SELECT id, nome, razao_social, cnpj, estado, cod_vendedor, segmento,
                           CASE WHEN CHAR_LENGTH(REGEXP_REPLACE(cnpj, '[^0-9]', '')) IN (12, 13)
                                THEN LPAD(REGEXP_REPLACE(cnpj, '[^0-9]', ''), 14, '0')
                                ELSE NULLIF(REGEXP_REPLACE(cnpj, '[^0-9]', ''), '') END AS digitos
                    FROM leads
                    WHERE status <> 'excluido' AND etapa <> 'outros' AND segmento IS NOT NULL
                ) l
                -- Supermercadista é só o ranking da ABRAS (`trocarSupermercadistaPeloRanking()`).
                JOIN segmentos s ON s.nome = l.segmento AND s.codigo <> ?
                LEFT JOIN cnpj_situacoes cs ON cs.cnpj = l.digitos
                WHERE l.digitos IS NULL
                   OR (CHAR_LENGTH(l.digitos) = 14 AND NOT EXISTS (SELECT 1 FROM vdu_raiz_cliente r WHERE r.raiz = LEFT(l.digitos, 8)))
                   OR (CHAR_LENGTH(l.digitos) <> 14 AND NOT EXISTS (SELECT 1 FROM clientes c WHERE c.cnpj_digitos = l.digitos))
                SQL, [$agora, Segmento::CODIGO_SUPERMERCADISTA]);
            DB::statement('DROP TEMPORARY TABLE vdu_raiz_cliente');

            self::preencherBusca();

            return [
                'clientes' => DB::table('visao_diretor_universo')->where('tipo', 'cliente')->count(),
                'leads' => DB::table('visao_diretor_universo')->where('tipo', 'lead')->count(),
            ];
        });
    }

    /**
     * A aba Supermercadista é SÓ o ranking da ABRAS (Tony, 2026-10-19): uma linha por rede
     * do ranking (`contas_estrategicas.posicao_abras`), na ordem dele, e nenhuma outra.
     *
     * - Os clientes da rede são os de `ClientesDaConta` (os vínculos que o
     *   `diretor:importar-ranking-abras` faz pela razão social, ou os manuais) — a mesma
     *   definição do `?conta_alvo=` da Carteira, que é o que o clique na linha abre. Filiais,
     *   última compra (status) e capital são desses clientes; a âncora segue a régua da
     *   linha de empresa (filial ativa na Receita, matriz, menor id).
     * - Rede sem cliente nosso vira linha de tipo `lead` (status "Lead", sem filiais).
     * - Sai a linha de EMPRESA de Supermercadista, e sai a empresa de qualquer aba que esteja
     *   ligada a uma rede do ranking — ela já está dentro da linha da rede, e cada empresa
     *   continua aparecendo numa aba só.
     * - Os leads de Supermercadista não entram (filtro no INSERT dos leads).
     *
     * ⚠️ Consequência aceita: o supermercado que não está no ranking some da página (continua
     * na Carteira e nos Leads), e a soma das filiais das abas deixa de ser a Carteira inteira.
     */
    private function trocarSupermercadistaPeloRanking(Carbon $agora): void
    {
        $empresa = self::EMPRESA;
        $empresaA = str_replace('c.', 'a.', $empresa);
        $segmento = Segmento::CODIGO_SUPERMERCADISTA;

        $contas = DB::table('contas_estrategicas as ce')
            ->join('segmentos as s', 's.id', '=', 'ce.segmento_id')
            ->where('s.codigo', $segmento)
            ->whereNotNull('ce.posicao_abras')
            ->pluck('ce.id')
            ->all();

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_rede_cliente');
        DB::statement('CREATE TEMPORARY TABLE vdu_rede_cliente (conta_id BIGINT UNSIGNED, cliente_id BIGINT UNSIGNED, PRIMARY KEY (conta_id, cliente_id))');
        // UNION distinto: o par não se repete (ver `paresDeTodasAsContas()`).
        DB::table('vdu_rede_cliente')->insertUsing(
            ['conta_id', 'cliente_id'],
            DB::query()->fromSub(app(ClientesDaConta::class)->paresDeTodasAsContas($contas), 'p')->select('conta_id', 'cliente_id'),
        );

        // As empresas das redes, com chave: o DELETE casa por igualdade, não por subconsulta.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_rede_empresa');
        DB::statement('CREATE TEMPORARY TABLE vdu_rede_empresa (empresa VARCHAR(30) PRIMARY KEY) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::insert(<<<SQL
            INSERT IGNORE INTO vdu_rede_empresa (empresa)
            SELECT DISTINCT {$empresa} FROM vdu_rede_cliente r JOIN clientes c ON c.id = r.cliente_id
            SQL);

        DB::delete(<<<SQL
            DELETE u FROM visao_diretor_universo u
            JOIN clientes a ON a.id = u.cliente_id
            LEFT JOIN vdu_rede_empresa e ON e.empresa = {$empresaA}
            WHERE u.tipo = 'cliente' AND (u.segmento_codigo = ? OR e.empresa IS NOT NULL)
            SQL, [$segmento]);
        DB::statement('DROP TEMPORARY TABLE vdu_rede_empresa');

        DB::insert(<<<'SQL'
            INSERT INTO visao_diretor_universo
                (segmento_codigo, tipo, conta_id, posicao, cliente_id, nome, uf, lojas,
                 data_ultima_compra, capital_social, capital_ordem, reconstruido_em)
            SELECT ?, IF(COUNT(c.id) > 0, 'cliente', 'lead'), ce.id, ce.posicao_abras,
                   SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY COALESCE(cs.situacao, 'ATIVA') <> 'ATIVA', SUBSTRING(c.cnpj_digitos, 9, 4) = '0001' DESC, c.id), ',', 1),
                   ce.nome, NULLIF(ce.uf, ''), COUNT(c.id),
                   MAX(c.data_ultima_compra), MAX(cs.capital_social), COALESCE(MAX(cs.capital_social), -1), ?
            FROM contas_estrategicas ce
            JOIN segmentos s ON s.id = ce.segmento_id AND s.codigo = ?
            LEFT JOIN vdu_rede_cliente r ON r.conta_id = ce.id
            LEFT JOIN clientes c ON c.id = r.cliente_id
            LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
            WHERE ce.posicao_abras IS NOT NULL
            GROUP BY ce.id, ce.posicao_abras, ce.nome, ce.uf
            SQL, [$segmento, $agora, $segmento]);
        DB::statement('DROP TEMPORARY TABLE vdu_rede_cliente');

        // Razão social, CNPJ, vendedor (e a UF, se a ABRAS não trouxe) são da âncora.
        DB::update(<<<'SQL'
            UPDATE visao_diretor_universo u
            JOIN clientes a ON a.id = u.cliente_id
            SET u.razao_social = a.razao_social,
                u.cod_cliente = a.cod_cliente,
                u.documento = a.cnpj,
                u.uf = COALESCE(u.uf, NULLIF(a.estado, '')),
                u.cod_vendedor = NULLIF(a.cod_vendedor, '')
            WHERE u.conta_id IS NOT NULL
            SQL);
    }

    /**
     * As empresas de uma mesma rede da Maiores redes viram UMA linha, com o nome da rede
     * (Tony, 2026-10-19: "Raia" em dez linhas, uma por razão social, não dizia nada ao dono).
     *
     * - A rede de cada empresa sai dos vínculos de `redes_mercado_vinculos` (cliente, não
     *   recusado, rede ativa) — todos os níveis: nome, grupo, produto, mesma empresa, manual.
     *   Empresa com códigos em duas redes fica na que tem mais filiais dela.
     * - Só junta rede com DUAS ou mais empresas; com uma só, a linha da empresa já é ela.
     * - A linha da rede é a soma das linhas das empresas: filiais, última compra, capital.
     *   Segmento = o que soma mais filiais entre elas (cada rede numa aba só); âncora = a
     *   empresa com mais filiais.
     * - As filiais que a linha conta vão para `visao_diretor_universo_filiais`, e é ESSA
     *   tabela que a Carteira filtra no `?rede_alvo=` (`FiliaisDaRede`). Contagem e lista
     *   saem da mesma fonte: o número clicado bate com a lista aberta.
     *
     * Roda depois de `trocarSupermercadistaPeloRanking()`: só junta linhas de empresa que
     * sobraram (a aba Supermercadista e as empresas das redes da ABRAS já saíram).
     *
     * ⚠️ Os passos passam por tabelas TEMPORÁRIAS separadas porque o MySQL não reabre a
     * mesma temporária duas vezes num statement ("Can't reopen table").
     */
    private function juntarPorRede(Carbon $agora): void
    {
        $empresa = self::EMPRESA;
        $empresaA = str_replace('c.', 'a.', $empresa);
        $charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        DB::table('visao_diretor_universo_filiais')->delete();

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_empresa_linha');
        DB::statement("CREATE TEMPORARY TABLE vdu_empresa_linha (empresa VARCHAR(30) PRIMARY KEY, universo_id BIGINT UNSIGNED, seg VARCHAR(10), lojas INT) {$charset}");
        DB::insert(<<<SQL
            INSERT IGNORE INTO vdu_empresa_linha (empresa, universo_id, seg, lojas)
            SELECT {$empresaA}, u.id, u.segmento_codigo, u.lojas
            FROM visao_diretor_universo u
            JOIN clientes a ON a.id = u.cliente_id
            WHERE u.tipo = 'cliente' AND u.conta_id IS NULL
            SQL);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_empresa_rede');
        DB::statement("CREATE TEMPORARY TABLE vdu_empresa_rede (empresa VARCHAR(30) PRIMARY KEY, rede_id BIGINT UNSIGNED, KEY (rede_id)) {$charset}");
        DB::insert(<<<SQL
            INSERT INTO vdu_empresa_rede (empresa, rede_id)
            SELECT empresa, rede_id FROM (
                SELECT e.empresa, v.rede_id,
                       ROW_NUMBER() OVER (PARTITION BY e.empresa ORDER BY COUNT(*) DESC, v.rede_id) AS rn
                FROM redes_mercado_vinculos v
                JOIN redes_mercado r ON r.id = v.rede_id AND r.ativa = 1
                JOIN clientes c ON c.cod_cliente = v.codigo
                JOIN vdu_empresa_linha e ON e.empresa = {$empresa}
                WHERE v.tipo = 'cliente' AND v.recusado = 0
                GROUP BY e.empresa, v.rede_id
            ) t WHERE rn = 1
            SQL);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_rede_junta');
        DB::statement('CREATE TEMPORARY TABLE vdu_rede_junta (rede_id BIGINT UNSIGNED PRIMARY KEY)');
        DB::insert('INSERT INTO vdu_rede_junta (rede_id) SELECT rede_id FROM vdu_empresa_rede GROUP BY rede_id HAVING COUNT(*) >= 2');
        DB::delete('DELETE er FROM vdu_empresa_rede er LEFT JOIN vdu_rede_junta j ON j.rede_id = er.rede_id WHERE j.rede_id IS NULL');
        DB::statement('DROP TEMPORARY TABLE vdu_rede_junta');

        DB::insert(<<<SQL
            INSERT INTO visao_diretor_universo_filiais (rede_id, cliente_id)
            SELECT er.rede_id, c.id FROM clientes c JOIN vdu_empresa_rede er ON er.empresa = {$empresa}
            SQL);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_rede_linha');
        DB::statement("CREATE TEMPORARY TABLE vdu_rede_linha (rede_id BIGINT UNSIGNED PRIMARY KEY, seg VARCHAR(10), empresas INT, lojas INT, uc DATE, cap DECIMAL(18,2), porte VARCHAR(10), ancora BIGINT UNSIGNED) {$charset}");
        DB::insert(<<<'SQL'
            INSERT INTO vdu_rede_linha (rede_id, empresas, lojas, uc, cap, porte, ancora)
            SELECT er.rede_id, COUNT(*), SUM(u.lojas), MAX(u.data_ultima_compra), MAX(u.capital_social),
                   ELT(MAX(FIELD(u.porte, 'ME', 'EPP', 'DEMAIS')), 'ME', 'EPP', 'DEMAIS'),
                   SUBSTRING_INDEX(GROUP_CONCAT(u.cliente_id ORDER BY u.lojas DESC, u.capital_ordem DESC, u.id), ',', 1)
            FROM vdu_empresa_rede er
            JOIN vdu_empresa_linha e ON e.empresa = er.empresa
            JOIN visao_diretor_universo u ON u.id = e.universo_id
            GROUP BY er.rede_id
            SQL);
        DB::update(<<<'SQL'
            UPDATE vdu_rede_linha l
            JOIN (
                SELECT rede_id, seg FROM (
                    SELECT er.rede_id, e.seg,
                           ROW_NUMBER() OVER (PARTITION BY er.rede_id ORDER BY SUM(e.lojas) DESC, MAX(e.lojas) DESC, e.seg) AS rn
                    FROM vdu_empresa_rede er
                    JOIN vdu_empresa_linha e ON e.empresa = er.empresa
                    GROUP BY er.rede_id, e.seg
                ) t WHERE rn = 1
            ) s ON s.rede_id = l.rede_id
            SET l.seg = s.seg
            SQL);

        DB::delete(<<<'SQL'
            DELETE u FROM visao_diretor_universo u
            JOIN vdu_empresa_linha e ON e.universo_id = u.id
            JOIN vdu_empresa_rede er ON er.empresa = e.empresa
            SQL);

        DB::insert(<<<'SQL'
            INSERT INTO visao_diretor_universo
                (segmento_codigo, tipo, rede_id, empresas, cliente_id, nome, uf, lojas,
                 data_ultima_compra, capital_social, capital_ordem, porte, reconstruido_em)
            SELECT l.seg, 'cliente', r.id, l.empresas, l.ancora, r.nome, NULLIF(r.uf, ''), l.lojas,
                   l.uc, l.cap, COALESCE(l.cap, -1), l.porte, ?
            FROM vdu_rede_linha l
            JOIN redes_mercado r ON r.id = l.rede_id
            SQL, [$agora]);

        DB::update(<<<'SQL'
            UPDATE visao_diretor_universo u
            JOIN clientes a ON a.id = u.cliente_id
            SET u.razao_social = a.razao_social,
                u.cod_cliente = a.cod_cliente,
                u.documento = a.cnpj,
                u.uf = COALESCE(u.uf, NULLIF(a.estado, '')),
                u.cod_vendedor = NULLIF(a.cod_vendedor, '')
            WHERE u.rede_id IS NOT NULL
            SQL);

        DB::statement('DROP TEMPORARY TABLE vdu_rede_linha');
        DB::statement('DROP TEMPORARY TABLE vdu_empresa_rede');
        DB::statement('DROP TEMPORARY TABLE vdu_empresa_linha');
    }

    /**
     * As colunas que a busca lê (`busca_texto`, `documento_digitos`), no formato de
     * `TermoDeBusca::sqlNormalizado()`. Chamada no fim de `reconstruir()` e pela migration
     * que criou as colunas — o mesmo código, para as duas nunca divergirem.
     */
    public static function preencherBusca(): void
    {
        $texto = TermoDeBusca::expressaoNormalizada(['nome', 'razao_social', 'nome_fantasia']);

        DB::update("UPDATE visao_diretor_universo SET busca_texto = {$texto}, documento_digitos = NULLIF(REGEXP_REPLACE(COALESCE(documento, ''), '[^0-9]', ''), '')");
    }

    /**
     * O segmento de cada EMPRESA, na tabela temporária `vdu_segmento_empresa`
     * (`empresa`, `seg`, `filiais` no segmento dela, `total` de
     * filiais com segmento conhecido, `empate`).
     *
     * O segmento dela é o que tem MAIS FILIAIS; no empate, o da matriz (/0001); sem matriz
     * no empate, o da filial cadastrada primeiro. Só conta filial com segmento conhecido.
     *
     * ⚠️ Decisão do Tony (2026-10-15): uma empresa, uma aba. O cadastro do TOTVS tem filial
     * no segmento errado (AMPLA ENERGIA com 1 filial em Supermercadista, LOJAS RENNER com 1
     * em Drogarias), e a regra antiga — aparece em todo segmento com alguma filial — levava a
     * empresa inteira para a aba errada. Consequência aceita: a aba deixa de bater com
     * `/carteira?segmento=X`, que continua contando a filial onde ela está cadastrada.
     *
     * É também a régua da lista de filiais fora do segmento que vai para o Cadastro
     * (`visao-diretor:filiais-fora-do-segmento`): as duas leem daqui, e por isso a regra mora
     * num lugar só. `empate` = quantos segmentos dividem o maior número de filiais (1 = sem
     * empate); com empate, o segmento escolhido é palpite e a lista pede conferência.
     */
    public static function criarSegmentoDaEmpresa(): void
    {
        $empresa = self::EMPRESA;

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_segmento_empresa');
        DB::statement('CREATE TEMPORARY TABLE vdu_segmento_empresa (empresa VARCHAR(30) PRIMARY KEY, seg VARCHAR(10), filiais INT, total INT, empate INT) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::insert(<<<SQL
            INSERT INTO vdu_segmento_empresa (empresa, seg, filiais, total, empate)
            SELECT empresa, seg, filiais, total, empate FROM (
                SELECT empresa, seg, filiais,
                       SUM(filiais) OVER (PARTITION BY empresa) AS total,
                       SUM(filiais = maior) OVER (PARTITION BY empresa) AS empate,
                       ROW_NUMBER() OVER (PARTITION BY empresa ORDER BY filiais DESC, tem_matriz DESC, primeiro) AS rn
                FROM (
                    SELECT empresa, seg, filiais, tem_matriz, primeiro,
                           MAX(filiais) OVER (PARTITION BY empresa) AS maior
                    FROM (
                        SELECT {$empresa} AS empresa, c.cod_segmento AS seg, COUNT(*) AS filiais,
                               MAX(SUBSTRING(c.cnpj_digitos, 9, 4) = '0001') AS tem_matriz,
                               MIN(c.id) AS primeiro
                        FROM clientes c
                        JOIN segmentos s ON s.codigo = c.cod_segmento
                        GROUP BY empresa, c.cod_segmento
                    ) p
                ) q
            ) r WHERE rn = 1
            SQL);
    }

    /**
     * As filiais cadastradas FORA do segmento da própria empresa (`criarSegmentoDaEmpresa()`),
     * para o Cadastro corrigir no TOTVS — o CRM não edita cliente (Regra de ouro nº 4).
     * São exatamente as filiais que a Visão Diretor deixou de mostrar em outra aba.
     *
     * @return list<array<string, mixed>>
     */
    public function filiaisForaDoSegmento(): array
    {
        $empresa = self::EMPRESA;
        self::criarSegmentoDaEmpresa();

        $linhas = DB::select(<<<SQL
            SELECT CASE WHEN CHAR_LENGTH(c.cnpj_digitos) = 14 THEN LEFT(c.cnpj, 10) ELSE c.cod_cliente END AS empresa,
                   se.nome AS segmento_empresa, v.filiais, v.total, v.empate,
                   c.cod_cliente, c.loja, c.cnpj, c.razao_social, c.nome_fantasia,
                   sc.nome AS segmento_cadastrado, cs.situacao, c.data_ultima_compra, c.cod_vendedor
            FROM clientes c
            JOIN vdu_segmento_empresa v ON v.empresa = {$empresa}
            JOIN segmentos sc ON sc.codigo = c.cod_segmento
            JOIN segmentos se ON se.codigo = v.seg
            LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
            WHERE c.cod_segmento <> v.seg
            ORDER BY v.empate > 1, v.total DESC, empresa, c.cod_cliente, c.loja
            SQL);
        DB::statement('DROP TEMPORARY TABLE vdu_segmento_empresa');

        return array_map(fn ($l) => (array) $l, $linhas);
    }

    /** Quando a tabela foi reconstruída pela última vez (null = nunca). */
    public function reconstruidoEm(): ?Carbon
    {
        $valor = DB::table('visao_diretor_universo')->max('reconstruido_em');

        return $valor ? Carbon::parse($valor) : null;
    }

    /** O status de cada linha: o mesmo corte da Carteira, mais `lead`. */
    public const STATUS_DA_LINHA = ['ativo', 'inativando', 'inativo', 'lead'];

    /**
     * `conquistar` = perdendo + a trabalhar + leads: onde a Autopel não vende hoje. Na tela é
     * "A trabalhar", um tile e uma coluna do Resumo (Tony, 2026-10-15); a pill de cada linha continua dizendo qual dos três.
     */
    public const A_CONQUISTAR = ['inativando', 'inativo', 'lead'];

    /** Os filtros de status aceitos: os da linha (links antigos) e o agrupado. */
    public const STATUS = [...self::STATUS_DA_LINHA, 'conquistar'];

    /**
     * O Resumo: uma linha por segmento com clientes e leads por status, do maior segmento
     * para o menor (é também a ordem das abas). Uma varredura da tabela.
     *
     * ⚠️ Cada número é o tile da aba daquele segmento — mesma tabela, mesma conta. Cada
     * empresa conta num segmento só (o dela), então a soma das abas é o total de empresas.
     *
     * @return list<array{codigo: string, nome: string, total: int, clientes: int, leads: int, ativo: int, inativando: int, inativo: int, lead: int}>
     */
    public function resumo(): array
    {
        [$ativo, $inativando] = $this->limites();

        $porSegmento = $this->selecionarContagens(DB::table('visao_diretor_universo'), $ativo, $inativando)
            ->addSelect('segmento_codigo')
            ->groupBy('segmento_codigo')
            ->get()
            ->keyBy('segmento_codigo');

        return Segmento::query()->get(['codigo', 'nome'])
            ->map(fn (Segmento $s) => [
                'codigo' => (string) $s->codigo,
                'nome' => $s->nome,
                ...$this->contagensDe($porSegmento->get((string) $s->codigo)),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Uma página da tabela do segmento: clientes e leads misturados, do maior capital
     * social ao menor.
     *
     * @param  array{status?: string, busca?: string, uf?: string, pagina?: int}  $filtros
     */
    public function lista(string $segmento, array $filtros = []): array
    {
        $status = $this->status($filtros['status'] ?? '');
        $pagina = max(1, (int) ($filtros['pagina'] ?? 1));
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $uf = (string) ($filtros['uf'] ?? '');

        $base = $this->base($segmento, $busca, $uf);

        /*
         * As contagens IGNORAM o filtro de status (os tiles são o próprio filtro: um filtro
         * não se aplica à faceta que controla — mesma regra do card da Carteira). Busca e
         * UF continuam valendo.
         */
        [$ativo, $inativando] = $this->limites();
        $contagens = $this->contagensDe($this->selecionarContagens(clone $base, $ativo, $inativando)->first());

        $filtrada = clone $base;
        $this->aplicarStatus($filtrada, $status);

        $linhas = $this->ordenar($filtrada, $segmento, $busca)
            ->offset(($pagina - 1) * self::POR_PAGINA)
            ->limit(self::POR_PAGINA)
            ->get();

        return [
            'segmento' => $segmento,
            // Paginador do Laravel, no formato que `Pagination.vue` já consome. Quem chama
            // decide o caminho dos links (`withPath`); o parâmetro da página é `pagina`.
            'linhas' => new LengthAwarePaginator(
                $this->montarLinhas($linhas),
                $status === '' ? $contagens['total'] : $contagens[$status],
                self::POR_PAGINA,
                $pagina,
                ['pageName' => 'pagina'],
            ),
            'contagens' => $contagens,
            // Com busca, a lista é de TODOS os segmentos (ver `base()`): a tela mostra a coluna Segmento.
            'todosOsSegmentos' => $busca !== '',
            'filtros' => ['status' => $status, 'busca' => $busca, 'uf' => $uf],
            'reconstruidoEm' => $this->reconstruidoEm()?->toIso8601String(),
        ];
    }

    /**
     * A tabela inteira, sem paginar, na ordem da tela — para o Excel. Mesmos filtros de
     * `lista()`, em lotes de 1.000.
     *
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function tudo(string $segmento, array $filtros = []): array
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $query = $this->base($segmento, $busca, (string) ($filtros['uf'] ?? ''));
        $this->aplicarStatus($query, $this->status($filtros['status'] ?? ''));

        $linhas = [];
        $this->ordenar($query, $segmento, $busca)->chunk(1000, function (Collection $lote) use (&$linhas) {
            array_push($linhas, ...$this->montarLinhas($lote));
        });

        return $linhas;
    }

    /** UFs que aparecem na tabela (opções do filtro do topo). */
    public function ufs(): array
    {
        return DB::table('visao_diretor_universo')->whereNotNull('uf')->distinct()->orderBy('uf')->pluck('uf')->all();
    }

    /** Valor do filtro de status, pela whitelist. Desconhecido vira "sem filtro". */
    public function status(string $valor): string
    {
        return in_array($valor, self::STATUS, true) ? $valor : '';
    }

    /**
     * Do maior capital ao menor; `id` desempata no mesmo sentido (o índice atende os dois).
     * Com busca (que atravessa os segmentos), as linhas da aba aberta vêm antes.
     */
    private function ordenar(Builder $query, string $segmento, string $busca): Builder
    {
        if ($busca !== '') {
            $query->orderByRaw('segmento_codigo <> ?', [$segmento]);
        }

        // Supermercadista é o ranking da ABRAS: a ordem dele (`trocarSupermercadistaPeloRanking()`).
        if ($segmento === Segmento::CODIGO_SUPERMERCADISTA) {
            $query->orderBy('posicao');
        }

        return $query->orderByDesc('capital_ordem')->orderByDesc('id');
    }

    /**
     * Os clientes e leads do segmento, com busca e UF.
     *
     * ⚠️ COM BUSCA, O SEGMENTO NÃO RECORTA (Tony, 2026-10-14): o cadastro do TOTVS tem muito
     * cliente no segmento errado, e procurar uma drogaria na aba Drogarias sem achá-la, por
     * estar cadastrada como supermercado, faz parecer que ela não existe. A busca procura em
     * todos os segmentos; a aba aberta só ordena (as linhas dela primeiro) e a tela marca o
     * que está em outro segmento. Cada empresa aparece uma vez, no segmento dela.
     * Tiles e lista continuam saindo desta mesma base, então continuam batendo.
     */
    private function base(string $segmento, string $busca, string $uf): Builder
    {
        $query = DB::table('visao_diretor_universo');

        if ($busca === '') {
            $query->where('segmento_codigo', $segmento);
        }

        if ($uf !== '') {
            $query->where('uf', $uf);
        }

        if ($busca !== '') {
            // Começo de palavra (`TermoDeBusca`), nas colunas que `preencherBusca()` normalizou.
            [$casa, $bindings] = TermoDeBusca::sqlNormalizado('busca_texto', $busca, 'documento_digitos');
            $query->whereRaw($casa, $bindings);
        }

        return $query;
    }

    /** Mesmo corte da Carteira (`ClienteStatusResolver`), na mesma formulação de datas. */
    private function aplicarStatus(Builder $query, string $status): void
    {
        if ($status === '') {
            return;
        }

        [$ativo, $inativando] = $this->limites();

        match ($status) {
            'lead' => $query->where('tipo', 'lead'),
            'ativo' => $query->where('tipo', 'cliente')->where('data_ultima_compra', '>=', $ativo),
            'inativando' => $query->where('tipo', 'cliente')
                ->where('data_ultima_compra', '<', $ativo)
                ->where('data_ultima_compra', '>=', $inativando),
            'inativo' => $query->where('tipo', 'cliente')
                ->where(fn (Builder $d) => $d->whereNull('data_ultima_compra')->orWhere('data_ultima_compra', '<', $inativando)),
            // O complemento de 'ativo': lead, ou cliente sem compra no último corte.
            'conquistar' => $query->where(fn (Builder $q) => $q->where('tipo', 'lead')
                ->orWhere(fn (Builder $c) => $c->where('tipo', 'cliente')
                    ->where(fn (Builder $d) => $d->whereNull('data_ultima_compra')->orWhere('data_ultima_compra', '<', $ativo)))),
        };
    }

    /** As colunas de contagem por status — a mesma conta de `aplicarStatus()`. */
    private function selecionarContagens(Builder $query, string $ativo, string $inativando): Builder
    {
        return $query
            ->selectRaw("SUM(tipo = 'cliente') as clientes")
            ->selectRaw("SUM(tipo = 'lead') as leads")
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra >= ?) as ativo", [$ativo])
            ->selectRaw("SUM(tipo = 'cliente' AND data_ultima_compra < ? AND data_ultima_compra >= ?) as inativando", [$ativo, $inativando])
            ->selectRaw("SUM(tipo = 'cliente' AND (data_ultima_compra IS NULL OR data_ultima_compra < ?)) as inativo", [$inativando]);
    }

    /** @return array{total: int, clientes: int, leads: int, ativo: int, inativando: int, inativo: int, lead: int, conquistar: int} */
    private function contagensDe(?object $r): array
    {
        $c = [
            'clientes' => (int) ($r->clientes ?? 0),
            'leads' => (int) ($r->leads ?? 0),
            'ativo' => (int) ($r->ativo ?? 0),
            'inativando' => (int) ($r->inativando ?? 0),
            'inativo' => (int) ($r->inativo ?? 0),
        ];

        return [
            'total' => $c['clientes'] + $c['leads'],
            ...$c,
            'lead' => $c['leads'],
            'conquistar' => $c['inativando'] + $c['inativo'] + $c['leads'],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function limites(): array
    {
        return [
            $this->statusResolver->limiteAtivo()->toDateString(),
            $this->statusResolver->limiteInativando()->toDateString(),
        ];
    }

    /**
     * As linhas da tela e do Excel, com o nome do vendedor. Uma consulta para o lote inteiro
     * (30 na tela, 1.000 por lote no Excel), nunca por linha.
     *
     * @param  Collection<int, object>  $linhas
     */
    public function montarLinhas(Collection $linhas): array
    {
        $nomes = $this->nomeVendedor->porCodigo($linhas->pluck('cod_vendedor')->filter()->unique());
        $hoje = Carbon::now();
        // São ~25 segmentos: uma consulta por lote, sem join na listagem.
        $segmentos = Segmento::query()->pluck('nome', 'codigo');

        /*
         * Grupo do TOTVS da matriz (âncora) de cada empresa. Lido aqui, uma consulta por
         * lote, e não gravado na tabela: renomear um grupo vale no request seguinte.
         * CLIENTES DIVERSOS (9998) é "sem grupo" e sai vazio.
         */
        $grupos = DB::table('clientes as a')
            ->join('grupos_cliente as g', 'g.codigo', '=', 'a.cod_grupo')
            ->whereIn('a.id', $linhas->pluck('cliente_id')->filter()->all())
            ->where('a.cod_grupo', '<>', GrupoCliente::SEM_GRUPO)
            ->pluck('g.nome', 'a.id');

        return $linhas->map(function ($l) use ($nomes, $hoje, $grupos, $segmentos) {
            $ehCliente = $l->tipo === 'cliente';
            $uc = $l->data_ultima_compra ? Carbon::parse($l->data_ultima_compra) : null;
            $digitos = preg_replace('/\D/', '', (string) $l->documento);

            return [
                'chave' => "{$l->tipo}-{$l->id}",
                'tipo' => $l->tipo,
                // A âncora (matriz) e o lead: são o que os botões de cartão CNPJ e mapa usam.
                'clienteId' => $l->cliente_id,
                'leadId' => $l->lead_id,
                'temCnpj' => ! $l->rede_id && strlen($digitos) === 14,
                /*
                 * A linha do cliente é a EMPRESA (raiz do CNPJ), que pode ter mais de um
                 * código no TOTVS. A Carteira busca CNPJ por trecho, então a raiz mascarada
                 * ("45.543.915") abre todas as filiais dela; sem CNPJ, o código.
                 */
                'buscaCarteira' => strlen($digitos) === 14 ? substr((string) $l->documento, 0, 10) : $l->cod_cliente,
                // Rede do ranking da ABRAS (aba Supermercadista): a posição e a conta, que é o
                // que o clique abre na Carteira (`?conta_alvo=`).
                'posicao' => $l->posicao !== null ? (int) $l->posicao : null,
                'contaId' => $l->conta_id,
                // Rede da Maiores redes que juntou várias empresas (`juntarPorRede()`): o
                // clique abre as filiais dela na Carteira (`?rede_alvo=`).
                'redeId' => $l->rede_id,
                'empresas' => $l->empresas !== null ? (int) $l->empresas : null,
                'nome' => $l->nome,
                'razaoSocial' => $l->razao_social,
                /*
                 * Fantasia só quando diz algo a mais que a razão social. Na rede da ABRAS o
                 * nome é o do ranking, e embaixo vai a razão social da âncora. Na rede juntada
                 * nenhum dos dois: a âncora pode ser um franqueado ("PATRICIA FERNANDA… LTDA"
                 * sob CHIQUINHO SORVETES), e o CNPJ dele não é o da rede.
                 */
                'nomeFantasia' => match (true) {
                    (bool) $l->rede_id => null,
                    (bool) $l->conta_id => $l->razao_social && mb_strtoupper($l->razao_social) !== mb_strtoupper($l->nome) ? $l->razao_social : null,
                    default => $l->nome_fantasia && mb_strtoupper($l->nome_fantasia) !== mb_strtoupper($l->nome) ? $l->nome_fantasia : null,
                },
                'documento' => $l->rede_id ? null : $l->documento,
                'uf' => $l->uf,
                'segmentoCodigo' => (string) $l->segmento_codigo,
                'segmentoNome' => $segmentos[$l->segmento_codigo] ?? (string) $l->segmento_codigo,
                // Todas as filiais da empresa. Lead não tem filial nossa: nulo ("—").
                'filiais' => $ehCliente ? (int) $l->lojas : null,
                'status' => $ehCliente ? $this->statusResolver->statusPara($uc, $hoje) : 'lead',
                'grupo' => $l->cliente_id ? ($grupos[$l->cliente_id] ?? null) : null,
                'vendedor' => $l->cod_vendedor ? ($nomes[$l->cod_vendedor] ?? $l->cod_vendedor) : null,
            ];
        })->values()->all();
    }

    /** Segmentos que existem, para validar o `?segmento=`. */
    public static function segmentoValido(string $codigo): bool
    {
        return $codigo !== '' && Segmento::query()->where('codigo', $codigo)->exists();
    }
}
