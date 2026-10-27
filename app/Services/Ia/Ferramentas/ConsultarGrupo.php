<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Faturamento\VolumePorFamilia;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Vendedores\NomeVendedorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Grupo de vendas do TOTVS (`GrpVendas`), não código de cliente.
 *
 * O número que a pessoa chama de "grupo 000258" é gravado sem zero à esquerda
 * (`258`). O cliente `000258` é outra empresa. Esta ferramenta existe para o
 * modelo não misturar os dois.
 *
 * As caixas saem de `faturamento_cliente_familia_mensal` (o rollup), nunca de
 * uma soma ao vivo em `faturamentos`.
 */
final class ConsultarGrupo implements Ferramenta
{
    private const MESES = 12;

    private const LIMITE_EMPRESAS = 12;

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly NomeVendedorResolver $nomes,
    ) {}

    public function nome(): string
    {
        return 'consultar_grupo';
    }

    public function rotuloProgresso(): string
    {
        return 'Consultando o grupo…';
    }

    public function descricao(): string
    {
        return 'Consulta um GRUPO de vendas do TOTVS (GrpVendas), pelo código ou pelo nome. '
            .'Use quando disserem "grupo", "grupo nr 000258" ou "quantas caixas este grupo compra por mês". '
            .'000258 é o grupo 258 — pode existir um cliente 000258 que é OUTRA empresa. Nunca passe esse '
            .'número para buscar_cliente nem numeros_do_cliente. Caixa de bobina é a família bobina '
            .'(unidade CX). 9998 é CLIENTES DIVERSOS e não tem volume. Não traz faturamento em reais.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'codigo' => [
                    'type' => ['string', 'null'],
                    'description' => 'Código do grupo, com ou sem zeros (000258 ou 258). Null se a busca for pelo nome.',
                ],
                'nome' => [
                    'type' => ['string', 'null'],
                    'description' => 'Parte do nome do grupo. Null se a busca for pelo código.',
                ],
                'familia' => [
                    'type' => 'string',
                    'enum' => VolumePorFamilia::familias(),
                    'description' => 'bobina, etiqueta ou tag. "Caixas de bobina" é bobina.',
                ],
            ],
            'required' => ['codigo', 'nome', 'familia'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $familia = (string) ($entrada['familia'] ?? '');

        if (! in_array($familia, VolumePorFamilia::familias(), true)) {
            return [
                'encontrado' => false,
                'mensagem' => 'Família inválida. Use bobina, etiqueta ou tag.',
            ];
        }

        $grupo = $this->acharGrupo($entrada);

        if ($grupo === null) {
            return ['encontrado' => false, 'mensagem' => 'Nenhum grupo de vendas com esse código ou nome.'];
        }

        if (is_array($grupo)) {
            return $grupo;
        }

        $base = [
            'encontrado' => true,
            'isto_e' => 'grupo de vendas (GrpVendas), não código de cliente',
            'codigo' => $grupo->codigo,
            'nome' => $grupo->nome,
            'familia' => $familia,
            'definicao' => VolumePorFamilia::definicao($familia),
        ];

        if ($grupo->codigo === GrupoCliente::SEM_GRUPO) {
            return $base + [
                'caixas_por_mes' => null,
                'mensagem' => '9998 é CLIENTES DIVERSOS, o balde de quem não tem grupo real. Não some caixas daqui.',
            ];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $visiveis = Cliente::query()
            ->where('cod_grupo', $grupo->codigo)
            ->when($codigos !== null, fn ($q) => $q->whereIn('cod_vendedor', $codigos))
            ->orderBy('razao_social')
            ->get(['cod_cliente', 'loja', 'razao_social', 'cod_vendedor']);

        if ($visiveis->isEmpty() && $codigos !== null) {
            return [
                'encontrado' => false,
                'mensagem' => 'Grupo não encontrado na carteira do usuário.',
            ];
        }

        $incluidos = $this->codigosInteiros($visiveis->pluck('cod_cliente')->unique()->all(), $grupo->codigo, $codigos);
        $volume = $this->volume($incluidos, $familia);
        $nomes = $this->nomes->porCodigo($visiveis->pluck('cod_vendedor'));
        $empresas = $visiveis->unique('cod_cliente')->take(self::LIMITE_EMPRESAS)->map(fn (Cliente $c) => [
            'razao_social' => $c->razao_social,
            'codigo_cliente' => $c->cod_cliente,
            'loja' => $c->loja,
            'vendedor' => $nomes[$c->cod_vendedor] ?? $c->cod_vendedor,
        ])->values()->all();

        return $base + [
            'escopo' => Formato::escopo($codigos, $contexto->user->roles->first()?->name),
            'empresas' => $empresas,
            'empresas_no_escopo' => $visiveis->unique('cod_cliente')->count(),
            'codigos_fora_do_volume' => $visiveis->pluck('cod_cliente')->unique()->count() - count($incluidos),
            'caixas_por_mes' => $volume['meses'],
            'media_mensal' => $volume['media'],
            'mensagem' => $volume['mensagem'],
        ] + array_filter([
            'aviso_codigo_de_cliente' => $this->homonimo($grupo->codigo, $codigos),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return GrupoCliente|array<string, mixed>|null
     */
    private function acharGrupo(array $entrada): GrupoCliente|array|null
    {
        $codigo = trim((string) ($entrada['codigo'] ?? ''));

        if ($codigo !== '') {
            $digitos = preg_replace('/\D/', '', $codigo) ?? '';
            $normalizado = $digitos !== '' && strlen($digitos) <= 6 ? (string) (int) $digitos : null;

            return $normalizado
                ? GrupoCliente::query()->where('codigo', $normalizado)->first()
                : null;
        }

        $nome = str_replace(['%', '_'], '', trim((string) ($entrada['nome'] ?? '')));

        if (mb_strlen($nome) < 3) {
            return ['encontrado' => false, 'mensagem' => 'Informe o código do grupo ou pelo menos 3 letras do nome.'];
        }

        $grupos = GrupoCliente::query()->where('nome', 'like', $nome.'%')->orderBy('nome')->limit(5)->get();

        if ($grupos->isEmpty()) {
            $grupos = GrupoCliente::query()->where('nome', 'like', '%'.$nome.'%')->orderBy('nome')->limit(5)->get();
        }

        if ($grupos->count() > 1) {
            return [
                'encontrado' => false,
                'candidatos' => $grupos->map(fn (GrupoCliente $g) => ['codigo' => $g->codigo, 'nome' => $g->nome])->all(),
                'mensagem' => 'Há mais de um grupo com esse nome. Chame de novo com o código.',
            ];
        }

        return $grupos->first();
    }

    /**
     * O rollup é do código inteiro. Código com filial de outro vendedor ou de outro
     * grupo fica de fora: a caixa dele incluiria nota que esta pessoa não atende.
     *
     * @param  list<string>  $codigosCliente
     * @param  array<string>|null  $escopo
     * @return list<string>
     */
    private function codigosInteiros(array $codigosCliente, string $codigoGrupo, ?array $escopo): array
    {
        if ($codigosCliente === []) {
            return [];
        }

        $filiais = Cliente::query()
            ->whereIn('cod_cliente', $codigosCliente)
            ->get(['cod_cliente', 'cod_grupo', 'cod_vendedor'])
            ->groupBy('cod_cliente');

        $incluidos = [];
        foreach ($filiais as $codigo => $doCodigo) {
            $soEsteGrupo = $doCodigo->every(fn (Cliente $f) => (string) $f->cod_grupo === $codigoGrupo);
            $todasVisiveis = $escopo === null || $doCodigo->every(
                fn (Cliente $f) => $f->cod_vendedor !== null && in_array($f->cod_vendedor, $escopo, true),
            );

            if ($soEsteGrupo && $todasVisiveis) {
                $incluidos[] = (string) $codigo;
            }
        }

        return $incluidos;
    }

    /**
     * @param  list<string>  $codigosCliente
     * @return array{meses: list<array<string, mixed>>, media: ?string, mensagem: ?string}
     */
    private function volume(array $codigosCliente, string $familia): array
    {
        $inicio = CarbonImmutable::today()->startOfMonth()->subMonths(self::MESES - 1);
        $fim = CarbonImmutable::today()->startOfMonth();
        $faixa = DB::table('faturamento_cliente_familia_mensal')->selectRaw('MIN(mes) as de, MAX(mes) as ate')->first();
        $de = $faixa->de ?? null;
        $ate = $faixa->ate ?? null;

        $somas = $codigosCliente === []
            ? collect()
            : DB::table('faturamento_cliente_familia_mensal')
                ->whereIn('cod_cliente', $codigosCliente)
                ->where('familia', $familia)
                ->whereBetween('mes', [$inicio->toDateString(), $fim->toDateString()])
                ->groupBy('mes')
                ->selectRaw('mes, SUM(quantidade) as quantidade, SUM(unidades_sem_caixa) as sem')
                ->get()
                ->keyBy(fn ($row) => substr((string) $row->mes, 0, 10));

        $meses = [];
        $calculados = [];
        $cursor = $inicio;
        while ($cursor->lessThanOrEqualTo($fim)) {
            $chave = $cursor->toDateString();
            $calculado = $de !== null && $chave >= substr((string) $de, 0, 10) && $chave <= substr((string) $ate, 0, 10);
            $linha = $somas->get($chave);
            $caixas = $calculado ? (float) ($linha->quantidade ?? 0) : null;
            $semTamanho = $calculado ? (float) ($linha->sem ?? 0) : null;
            $meses[] = [
                'mes' => $cursor->format('m/Y'),
                'caixas' => Formato::caixas($caixas),
                'unidades_sem_tamanho_de_caixa' => Formato::caixas($semTamanho),
                'calculado' => $calculado,
            ];
            if ($calculado) {
                $calculados[] = $caixas;
            }
            $cursor = $cursor->addMonth();
        }

        $mensagem = null;
        if ($calculados === []) {
            $mensagem = 'O volume em caixas deste período ainda não foi calculado. Não diga que é zero.';
        } elseif ($codigosCliente === []) {
            $mensagem = 'Há empresa do grupo na carteira, mas o código tem filial de outro vendedor ou de outro grupo, então o volume não sai (incluía nota de quem o usuário não atende).';
        }

        $media = $calculados === [] ? null : Formato::caixas(array_sum($calculados) / count($calculados));

        return ['meses' => $meses, 'media' => $media, 'mensagem' => $mensagem];
    }

    /**
     * @param  array<string>|null  $escopo
     */
    private function homonimo(string $codigoGrupo, ?array $escopo): ?string
    {
        $codigoCliente = str_pad($codigoGrupo, 6, '0', STR_PAD_LEFT);
        $cliente = Cliente::query()->where('cod_cliente', $codigoCliente)->orderBy('loja')->first();

        if ($cliente === null || (string) $cliente->cod_grupo === $codigoGrupo) {
            return null;
        }

        $ve = $escopo === null || ($cliente->cod_vendedor !== null && in_array($cliente->cod_vendedor, $escopo, true));

        if (! $ve) {
            return "O número {$codigoCliente} também é código de um cliente fora da carteira do usuário. O resultado acima é o GRUPO {$codigoGrupo}.";
        }

        return "O cliente {$codigoCliente} é {$cliente->razao_social} e NÃO é este grupo. O resultado acima é o GRUPO {$codigoGrupo} ({$cliente->cod_grupo} é o grupo daquele cliente).";
    }
}
