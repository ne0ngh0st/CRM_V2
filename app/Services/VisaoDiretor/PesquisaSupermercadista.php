<?php

namespace App\Services\VisaoDiretor;

use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Models\Segmento;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A lista da planilha de pesquisa do supermercadista (segmento 101).
 *
 * A tela continua sendo só o ranking da ABRAS. Esta lista é outra: cada empresa cliente
 * do segmento, cada lead de prospecção ou da base antiga que não seja essa empresa, e
 * cada rede do ranking que não ligou nenhuma delas. Montada na hora de gerar o Excel —
 * a página não chama este serviço.
 */
class PesquisaSupermercadista
{
    public function __construct(
        private readonly ClienteStatusResolver $status,
        private readonly NomeVendedorResolver $vendedores,
    ) {}

    /** @param  array{status?: string, busca?: string, uf?: string}  $filtros */
    public function contar(array $filtros = []): int
    {
        return count($this->linhas($filtros));
    }

    /**
     * Quem ainda não tem faturamento da ABRAS e não foi consultado na web neste ano.
     * A planilha não busca: ela lê o que este comando já gravou.
     *
     * @return list<array{chave: string, nome: string, uf: ?string, contaId: ?int}>
     */
    public function pendentesDeFaturamento(): array
    {
        $ano = (int) date('Y');
        $pendentes = [];

        foreach ($this->linhas() as $l) {
            if ($l['chaveFaturamento'] === null || $l['fonteFaturamento'] === FaturamentoDeMercado::FONTE_ABRAS) {
                continue;
            }
            $consultado = $l['consultadoEm'] ? (int) Carbon::parse($l['consultadoEm'])->year : null;
            if ($l['fonteFaturamento'] === FaturamentoDeMercado::FONTE_WEB && $consultado !== null && $consultado >= $ano) {
                continue;
            }

            $pendentes[] = [
                'chave' => $l['chaveFaturamento'],
                'nome' => $l['nome'],
                'uf' => $l['uf'],
                'contaId' => $l['contaId'],
            ];
        }

        // O crédito da busca é curto: quem tem mais capital social vem primeiro.
        // Sem capital (ou chave que não é CNPJ) fica no fim, nunca na frente de quem tem.
        $cnpjs = array_values(array_filter(array_column($pendentes, 'chave'), fn ($c) => strlen((string) $c) === 14));
        $capital = [];
        foreach (array_chunk($cnpjs, 1000) as $lote) {
            foreach (DB::table('cnpj_situacoes')->whereIn('cnpj', $lote)->pluck('capital_social', 'cnpj') as $cnpj => $valor) {
                $capital[$cnpj] = $valor === null ? -1 : (int) $valor;
            }
        }
        usort($pendentes, fn ($a, $b) => ($capital[$b['chave']] ?? -1) <=> ($capital[$a['chave']] ?? -1));

        return $pendentes;
    }

    /**
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     * @return list<array<string, mixed>>
     */
    public function linhas(array $filtros = []): array
    {
        $empresas = $this->empresas();
        $codigosDasEmpresas = [];
        foreach ($empresas as $e) {
            foreach ($e['codigos'] as $codigo) {
                $codigosDasEmpresas[$codigo] = true;
            }
        }

        $contas = $this->contasDoRanking();
        $contaPorCodigo = [];
        foreach ($contas as $conta) {
            foreach ($conta['codigos'] as $codigo) {
                $atual = $contaPorCodigo[$codigo] ?? null;
                if ($atual === null || $conta['posicao'] < $atual['posicao']) {
                    $contaPorCodigo[$codigo] = $conta;
                }
            }
        }

        $ligadas = [];
        foreach ($contas as $conta) {
            foreach ($conta['codigos'] as $codigo) {
                if (isset($codigosDasEmpresas[$codigo])) {
                    $ligadas[$conta['id']] = true;
                    break;
                }
            }
        }

        $ancoras = $this->ancoras(array_column($empresas, 'cliente_id'));
        $leads = $this->leads();
        $chaves = [];
        foreach ($ancoras as $a) {
            if (strlen((string) $a->cnpj_digitos) === 14) {
                $chaves[] = $a->cnpj_digitos;
            }
        }
        foreach ($leads as $l) {
            if (strlen((string) $l->digitos) === 14) {
                $chaves[] = $l->digitos;
            }
        }
        foreach ($contas as $conta) {
            if (! isset($ligadas[$conta['id']])) {
                $chaves[] = 'c'.$conta['id'];
            }
        }

        $faturamento = DB::table('faturamento_mercado')->whereIn('chave', $chaves ?: ['-'])->get()->keyBy('chave');
        $lojas = $this->lojas(array_values(array_filter($chaves, fn ($c) => strlen($c) === 14)));
        $grupos = DB::table('clientes as a')
            ->join('grupos_cliente as g', 'g.codigo', '=', 'a.cod_grupo')
            ->whereIn('a.id', array_column($empresas, 'cliente_id') ?: [0])
            ->where('a.cod_grupo', '<>', GrupoCliente::SEM_GRUPO)
            ->pluck('g.nome', 'a.id');
        $nomes = $this->vendedores->porCodigo(collect([
            ...array_map(fn ($a) => $a->cod_vendedor, $ancoras),
            ...array_map(fn ($l) => $l->cod_vendedor, $leads),
        ])->filter()->unique());
        $segmentoNome = (string) Segmento::query()->where('codigo', Segmento::CODIGO_SUPERMERCADISTA)->value('nome');
        $hoje = Carbon::now();

        $linhas = [];
        foreach ($empresas as $e) {
            $a = $ancoras[$e['cliente_id']] ?? null;
            if ($a === null) {
                continue;
            }
            $conta = null;
            foreach ($e['codigos'] as $codigo) {
                if (isset($contaPorCodigo[$codigo]) && ($conta === null || $contaPorCodigo[$codigo]['posicao'] < $conta['posicao'])) {
                    $conta = $contaPorCodigo[$codigo];
                }
            }
            $chave = strlen((string) $a->cnpj_digitos) === 14 ? $a->cnpj_digitos : null;
            $linhas[] = $this->linha(
                'cliente', $a->razao_social ?: ($a->nome_fantasia ?: $a->cod_cliente), $a->nome_fantasia,
                $a->cnpj, $a->estado, (int) $e['filiais'], $e['data_ultima_compra'],
                $grupos[$a->id] ?? null, $a->cod_vendedor ? ($nomes[$a->cod_vendedor] ?? $a->cod_vendedor) : null,
                $segmentoNome, $conta['posicao'] ?? null, $conta['id'] ?? null, $chave,
                $chave ? ($lojas[$chave] ?? null) : null, $chave ? ($faturamento[$chave] ?? null) : null, $hoje,
            );
        }

        foreach ($leads as $l) {
            $chave = strlen((string) $l->digitos) === 14 ? $l->digitos : null;
            $linhas[] = $this->linha(
                'lead', $l->nome, $l->nome_fantasia, $l->documento, $l->uf, null, null,
                null, $l->cod_vendedor ? ($nomes[$l->cod_vendedor] ?? $l->cod_vendedor) : null,
                $segmentoNome, null, null, $chave,
                $chave ? ($lojas[$chave] ?? null) : null, $chave ? ($faturamento[$chave] ?? null) : null, $hoje,
            );
        }

        foreach ($contas as $conta) {
            if (isset($ligadas[$conta['id']])) {
                continue;
            }
            $chave = 'c'.$conta['id'];
            $linhas[] = $this->linha(
                'rede', $conta['nome'], null, null, $conta['uf'], null, null,
                null, null, $segmentoNome, $conta['posicao'], $conta['id'], $chave,
                null, $faturamento[$chave] ?? null, $hoje,
            );
        }

        usort($linhas, function (array $a, array $b) {
            $pa = $a['posicao'] ?? PHP_INT_MAX;
            $pb = $b['posicao'] ?? PHP_INT_MAX;

            return $pa <=> $pb ?: strcasecmp((string) $a['nome'], (string) $b['nome']);
        });

        return $this->filtrar($linhas, $filtros);
    }

    /**
     * @return list<array{cliente_id: int, filiais: int, data_ultima_compra: ?string, codigos: list<string>}>
     */
    private function empresas(): array
    {
        $empresa = UniversoDoSegmento::EMPRESA;
        UniversoDoSegmento::criarSegmentoDaEmpresa();
        DB::statement('SET SESSION group_concat_max_len = 4194304');

        $linhas = DB::select(<<<SQL
            SELECT SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY COALESCE(cs.situacao, 'ATIVA') <> 'ATIVA', SUBSTRING(c.cnpj_digitos, 9, 4) = '0001' DESC, c.id), ',', 1) AS cliente_id,
                   COUNT(*) AS filiais,
                   MAX(c.data_ultima_compra) AS data_ultima_compra,
                   GROUP_CONCAT(DISTINCT c.cod_cliente) AS codigos
            FROM clientes c
            JOIN vdu_segmento_empresa v ON v.empresa = {$empresa} AND v.seg = ?
            LEFT JOIN cnpj_situacoes cs ON cs.cnpj = c.cnpj_digitos
            GROUP BY v.empresa
            HAVING SUM(COALESCE(cs.situacao, 'ATIVA') = 'ATIVA') > 0
            SQL, [Segmento::CODIGO_SUPERMERCADISTA]);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_segmento_empresa');

        return array_map(fn ($l) => [
            'cliente_id' => (int) $l->cliente_id,
            'filiais' => (int) $l->filiais,
            'data_ultima_compra' => $l->data_ultima_compra,
            'codigos' => $l->codigos ? explode(',', $l->codigos) : [],
        ], $linhas);
    }

    /** @param  list<int>  $ids */
    private function ancoras(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('clientes')->whereIn('id', $ids)->get([
            'id', 'cod_cliente', 'razao_social', 'nome_fantasia', 'cnpj', 'cnpj_digitos', 'estado', 'cod_vendedor',
        ])->keyBy('id')->all();
    }

    /** @return list<object> */
    private function leads(): array
    {
        $nome = Segmento::query()->where('codigo', Segmento::CODIGO_SUPERMERCADISTA)->value('nome');
        if (! $nome) {
            return [];
        }

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_raiz_cliente');
        DB::statement('CREATE TEMPORARY TABLE vdu_raiz_cliente (raiz CHAR(8) PRIMARY KEY) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO vdu_raiz_cliente (raiz)
            SELECT DISTINCT LEFT(cnpj_digitos, 8) FROM clientes WHERE CHAR_LENGTH(cnpj_digitos) = 14
            SQL);

        $origens = Lead::ORIGENS_IMPORTADAS;
        $linhas = DB::select(<<<'SQL'
            SELECT l.id, COALESCE(NULLIF(l.razao_social, ''), l.nome) AS nome, NULLIF(l.nome, '') AS nome_fantasia,
                   l.cnpj AS documento, NULLIF(l.estado, '') AS uf, NULLIF(l.cod_vendedor, '') AS cod_vendedor, l.digitos
            FROM (
                SELECT id, nome, razao_social, cnpj, estado, cod_vendedor,
                       CASE WHEN CHAR_LENGTH(REGEXP_REPLACE(cnpj, '[^0-9]', '')) IN (12, 13)
                            THEN LPAD(REGEXP_REPLACE(cnpj, '[^0-9]', ''), 14, '0')
                            ELSE NULLIF(REGEXP_REPLACE(cnpj, '[^0-9]', ''), '') END AS digitos
                FROM leads
                WHERE status <> 'excluido' AND etapa <> 'outros' AND segmento = ?
                  AND origem IN (?, ?)
            ) l
            WHERE l.digitos IS NULL
               OR (CHAR_LENGTH(l.digitos) = 14 AND NOT EXISTS (SELECT 1 FROM vdu_raiz_cliente r WHERE r.raiz = LEFT(l.digitos, 8)))
               OR (CHAR_LENGTH(l.digitos) <> 14 AND NOT EXISTS (SELECT 1 FROM clientes c WHERE c.cnpj_digitos = l.digitos))
            SQL, [$nome, ...$origens]);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_raiz_cliente');

        return $linhas;
    }

    /**
     * @return list<array{id: int, nome: string, uf: ?string, posicao: int, codigos: list<string>}>
     */
    private function contasDoRanking(): array
    {
        $contas = DB::table('contas_estrategicas as ce')
            ->join('segmentos as s', 's.id', '=', 'ce.segmento_id')
            ->where('s.codigo', Segmento::CODIGO_SUPERMERCADISTA)
            ->whereNotNull('ce.posicao_abras')
            ->get(['ce.id', 'ce.nome', 'ce.uf', 'ce.posicao_abras']);

        $porConta = DB::table('conta_estrategica_vinculos')
            ->whereIn('conta_id', $contas->pluck('id')->all() ?: [0])
            ->where('tipo', 'cliente')
            ->get(['conta_id', 'codigo'])
            ->groupBy('conta_id');

        return $contas->map(fn ($c) => [
            'id' => (int) $c->id,
            'nome' => $c->nome,
            'uf' => $c->uf,
            'posicao' => (int) $c->posicao_abras,
            'codigos' => ($porConta[$c->id] ?? collect())->pluck('codigo')->all(),
        ])->all();
    }

    /** @param  list<string>  $cnpjs  @return array<string, int|null> */
    private function lojas(array $cnpjs): array
    {
        $mapa = [];
        foreach (array_chunk($cnpjs, 1000) as $lote) {
            foreach (DB::table('cnpj_situacoes')->whereIn('cnpj', $lote)->get(['cnpj', 'lojas_receita']) as $s) {
                $mapa[$s->cnpj] = $s->lojas_receita === null ? null : (int) $s->lojas_receita;
            }
        }

        return $mapa;
    }

    private function linha(
        string $tipo, ?string $nome, ?string $fantasia, ?string $documento, ?string $uf, ?int $filiais,
        ?string $ultimaCompra, ?string $grupo, ?string $vendedor, string $segmentoNome, ?int $posicao,
        ?int $contaId, ?string $chave, mixed $lojas, ?object $fat, Carbon $hoje,
    ): array {
        $uc = $ultimaCompra ? Carbon::parse($ultimaCompra) : null;

        return [
            'tipo' => $tipo,
            'nome' => $nome ?: '',
            'nomeFantasia' => $fantasia && mb_strtoupper($fantasia) !== mb_strtoupper((string) $nome) ? $fantasia : null,
            'documento' => $documento,
            'grupo' => $grupo,
            'uf' => $uf,
            'filiais' => $filiais,
            'status' => $tipo === 'cliente' ? $this->status->statusPara($uc, $hoje) : 'lead',
            'vendedor' => $vendedor,
            'segmentoNome' => $segmentoNome,
            'posicao' => $posicao,
            'contaId' => $contaId,
            'chaveFaturamento' => $chave,
            'lojasReceita' => $lojas === null ? null : (int) $lojas,
            'faturamento' => $fat?->valor === null ? null : (int) $fat->valor,
            'anoFaturamento' => $fat?->ano === null ? null : (int) $fat->ano,
            'fonteFaturamento' => $fat?->fonte,
            'urlFaturamento' => $fat?->url,
            'consultadoEm' => $fat?->consultado_em,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     * @return list<array<string, mixed>>
     */
    private function filtrar(array $linhas, array $filtros): array
    {
        $status = (string) ($filtros['status'] ?? '');
        $aceitos = match (true) {
            $status === 'conquistar' => UniversoDoSegmento::A_CONQUISTAR,
            in_array($status, UniversoDoSegmento::STATUS_DA_LINHA, true) => [$status],
            default => null,
        };
        $busca = trim((string) ($filtros['busca'] ?? ''));
        $uf = (string) ($filtros['uf'] ?? '');

        return array_values(array_filter($linhas, function (array $l) use ($aceitos, $busca, $uf) {
            if ($aceitos !== null && ! in_array($l['status'], $aceitos, true)) {
                return false;
            }
            if ($uf !== '' && ($l['uf'] ?? '') !== $uf) {
                return false;
            }
            if ($busca === '') {
                return true;
            }

            foreach ([$l['nome'], $l['nomeFantasia'], $l['documento'], $l['grupo']] as $texto) {
                if (TermoDeBusca::casa($texto, $busca)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
