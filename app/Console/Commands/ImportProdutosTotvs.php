<?php

namespace App\Console\Commands;

use App\Services\Totvs\LeitorXlsx;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * `produtos` (busca do orçamento, Tabela de Preços, BI) a partir de dois relatórios do
 * TOTVS em xlsx, na pasta `produtos/`:
 *
 *   - 234 TABELA DE PRECO PADRAO: código, descrição, unidade e preço de tabela. Só tem
 *     bobina e etiqueta.
 *   - 005 CADASTRO DE PRODUTO COM NCM: o cadastro inteiro (~58 mil códigos), com grupo e
 *     se está ativo.
 *
 * Substitui o `legado:import-produtos`, que lia o CODIGO_PRODUTOS do legado e parou junto
 * com ele: produto criado depois (ex.: V40826) não aparecia na busca do orçamento.
 *
 * O QUE ENTRA (mesmo recorte que a base do legado tinha):
 *   1. todo código da tabela de preço;
 *   2. do cadastro, os produtos de VENDA ativos que não têm preço: código começando com
 *      "V". Os outros prefixos são intermediário (P), serviço/consumo (S) e matéria-prima
 *      (M), e não se vendem.
 *
 * ⚠️ NUNCA APAGA. Produto que sumiu dos relatórios fica, porque orçamento antigo aponta
 * para ele. Arquivo faltando ou truncado não derruba a corrente do `totvs:atualizar`: sai
 * com sucesso, sem gravar nada, e diz o motivo (mesmo desenho do Relatório de Revenda).
 *
 * ⚠️ A CATEGORIA só é preenchida quando o produto ainda não tem uma. As da base do
 * legado (BOBINA, ETIQUETA, TAG, SUPLY…) não saem limpas do grupo do TOTVS: TAG, por
 * exemplo, é grupo ETIQUETAS no cadastro. Produto novo ganha a categoria pelo grupo
 * (`categoriaDoGrupo()`), que é o que alimenta o filtro da Tabela de Preços e o Potencial.
 */
class ImportProdutosTotvs extends Command
{
    protected $signature = 'totvs:import-produtos
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa produtos e preço de tabela dos relatórios 234 e 005 do TOTVS (xlsx)';

    public const COLUNAS_PRECOS = ['CODIGO', 'DESCRICAO', 'UNIDADE', 'PRCVENDA'];

    public const COLUNAS_CADASTRO = ['CODIGO', 'DESCRICAO', 'DESC GRUPO', 'ATIVO'];

    /** Abaixo desta fração do que já está gravado, a tabela de preço é tratada como truncada. */
    private const FRACAO_MINIMA = 0.5;

    public function handle(): int
    {
        $precos = Relatorios::caminho('produtos_precos');
        $cadastro = Relatorios::caminho('produtos_cadastro');

        if ($precos === null || $cadastro === null) {
            $this->warn('Produtos NÃO importados: falta '.($precos === null ? 'a tabela de preço (234)' : 'o cadastro (005)').' na pasta produtos/.');

            return self::SUCCESS;
        }

        try {
            $cad = $this->lerCadastro($cadastro);
            $tabela = $this->lerPrecos($precos);
        } catch (Throwable $e) {
            $this->error('Produtos NÃO importados: '.$e->getMessage());

            return self::SUCCESS;
        }

        $atuais = DB::table('produtos')->whereNotNull('preco_tabela')->count();

        if ($tabela === [] || count($tabela) < $atuais * self::FRACAO_MINIMA) {
            $this->error(sprintf('Tabela de preço parece truncada (%d códigos contra %d com preço gravados): nada foi alterado.', count($tabela), $atuais));

            return self::SUCCESS;
        }

        $linhas = [];

        foreach ($tabela as $codigo => $p) {
            $linhas[$codigo] = [
                'cod_produto' => (string) $codigo,
                'descricao' => $p['descricao'],
                'categoria' => self::categoriaDoGrupo($cad[$codigo]['grupo'] ?? null),
                'unidade' => $p['unidade'],
                'preco_tabela' => $p['preco'],
            ];
        }

        $soCadastro = 0;

        foreach ($cad as $codigo => $c) {
            if (isset($linhas[$codigo]) || ! $c['ativo'] || ! str_starts_with($codigo, 'V')) {
                continue;
            }

            $linhas[$codigo] = [
                'cod_produto' => (string) $codigo,
                'descricao' => $c['descricao'],
                'categoria' => self::categoriaDoGrupo($c['grupo']),
                'unidade' => null,
                'preco_tabela' => null,
            ];
            $soCadastro++;
        }

        $existentes = DB::table('produtos')->whereIn('cod_produto', array_map('strval', array_keys($linhas)))->count();
        $novos = count($linhas) - $existentes;

        $this->line(sprintf(
            'Tabela de preço: %s códigos. Do cadastro, sem preço: %s. Novos no CRM: %s.',
            number_format(count($tabela), 0, ',', '.'),
            number_format($soCadastro, 0, ',', '.'),
            number_format($novos, 0, ',', '.'),
        ));

        if ($this->option('dry-run')) {
            $this->info('[dry-run] nada gravado.');

            return self::SUCCESS;
        }

        $agora = now();

        DB::transaction(function () use ($linhas, $agora) {
            foreach (array_chunk(array_values($linhas), 1000) as $lote) {
                DB::table('produtos')->upsert(
                    array_map(fn ($l) => $l + ['created_at' => $agora, 'updated_at' => $agora], $lote),
                    ['cod_produto'],
                    [
                        'descricao',
                        'unidade',
                        'preco_tabela',
                        'updated_at',
                        // Só preenche o que está vazio: ver o docblock da classe.
                        'categoria' => DB::raw('COALESCE(`produtos`.`categoria`, VALUES(`categoria`))'),
                    ],
                );
            }
        });

        $this->info('Produtos gravados: '.number_format(count($linhas), 0, ',', '.').'.');

        return self::SUCCESS;
    }

    /**
     * Grupo do cadastro (005) → categoria de `produtos`. Fica nas categorias que a base já
     * usa, porque a Tabela de Preços filtra por elas e o Potencial lê BOBINA/ETIQUETA/TAG.
     */
    public static function categoriaDoGrupo(?string $grupo): ?string
    {
        if ($grupo === null) {
            return null;
        }

        return match (true) {
            str_starts_with($grupo, 'BOBINA') => 'BOBINA',
            str_starts_with($grupo, 'ETIQUETA'), $grupo === 'IN MOLD LABEL' => 'ETIQUETA',
            str_starts_with($grupo, 'VOLANTE') => 'VOLANTE',
            $grupo === 'RASPADINHA', str_starts_with($grupo, 'PAPEIS CUTZ') => 'OUTROS',
            default => 'SUPLY',
        };
    }

    /** @return array<string, array{descricao: string, unidade: ?string, preco: ?float}> */
    private function lerPrecos(string $caminho): array
    {
        $saida = [];

        foreach ($this->linhasComCabecalho($caminho, self::COLUNAS_PRECOS) as $l) {
            $codigo = mb_substr(trim($l['CODIGO']), 0, 255);

            if ($codigo === '') {
                continue;
            }

            $preco = trim($l['PRCVENDA']);

            $saida[$codigo] = [
                'descricao' => self::descricao($l['DESCRICAO'], $codigo),
                'unidade' => Normalizador::valorOuNull(mb_substr(trim($l['UNIDADE']), 0, 10)),
                // Excel grava 0,6253 como 0.62528599999999999: 4 casas é o que a coluna guarda.
                'preco' => $preco === '' ? null : round((float) $preco, 4),
            ];
        }

        return $saida;
    }

    /**
     * O cadastro repete código (~95 mil linhas para ~58 mil códigos). Ativo em qualquer
     * linha conta como ativo.
     *
     * @return array<string, array{descricao: string, grupo: ?string, ativo: bool}>
     */
    private function lerCadastro(string $caminho): array
    {
        $saida = [];

        foreach ($this->linhasComCabecalho($caminho, self::COLUNAS_CADASTRO) as $l) {
            $codigo = mb_substr(trim($l['CODIGO']), 0, 255);

            if ($codigo === '') {
                continue;
            }

            $saida[$codigo] = [
                'descricao' => self::descricao($l['DESCRICAO'], $codigo),
                'grupo' => Normalizador::valorOuNull(mb_strtoupper(Normalizador::textoUtf8(trim($l['DESC GRUPO'])))),
                'ativo' => ($saida[$codigo]['ativo'] ?? false) || mb_strtoupper(trim($l['ATIVO'])) === 'SIM',
            ];
        }

        return $saida;
    }

    /**
     * Linhas indexadas pelo nome da coluna. O relatório traz o título do RLT na 1ª linha e
     * o cabeçalho na 2ª, com espaço de preenchimento ("Codigo      "): o cabeçalho é a
     * primeira linha que tem todas as colunas pedidas, comparadas sem espaço e sem caixa.
     *
     * @param  list<string>  $colunas
     * @return iterable<array<string, string>>
     */
    private function linhasComCabecalho(string $caminho, array $colunas): iterable
    {
        $indice = null;

        foreach (LeitorXlsx::linhas($caminho) as $linha) {
            if ($indice === null) {
                $nomes = array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $linha);
                $posicoes = array_flip($nomes);

                if (array_diff($colunas, $nomes) === []) {
                    $indice = array_intersect_key($posicoes, array_flip($colunas));
                }

                continue;
            }

            $saida = [];
            foreach ($indice as $nome => $posicao) {
                $saida[$nome] = (string) ($linha[$posicao] ?? '');
            }

            yield $saida;
        }

        if ($indice === null) {
            throw new RuntimeException(basename($caminho).' sem as colunas '.implode(', ', $colunas).'.');
        }
    }

    private static function descricao(string $valor, string $codigo): string
    {
        $valor = trim(Normalizador::textoUtf8($valor));

        return mb_substr($valor === '' ? $codigo : $valor, 0, 255);
    }
}
