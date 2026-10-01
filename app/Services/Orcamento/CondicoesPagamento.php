<?php

namespace App\Services\Orcamento;

use App\Models\CondicaoPagamento;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tudo o que o CRM sabe sobre condição de pagamento mora aqui (Regra de ouro nº 8):
 * carregar a lista do Protheus, oferecê-la ao formulário e reconhecer o texto antigo.
 *
 * Contexto (2026-10-01): o Portal passou a aceitar `paymentConditionCode` e só aceita
 * condições ativas no Protheus. Até aqui o orçamento guardava TEXTO livre
 * ("28/35/42DDL", "COMBINAR", "30/60 Dias após análise de crédito"), ~30% dele fora da
 * lista fixa — nada disso vira código sozinho. Ver docs/integracao-portal-pedidos.md §4.12.
 */
class CondicoesPagamento
{
    /** A lista versionada no repositório, carregada pela migration. */
    public const ARQUIVO = 'database/data/condicoes_pagamento.json';

    /**
     * Grava a lista e DESATIVA (nunca apaga) o que saiu dela: orçamentos antigos
     * continuam apontando para o código, e a descrição precisa continuar existindo.
     *
     * @param  iterable<array{codigo:string, descricao:string, tipo?:?string}>  $condicoes
     * @return array{gravadas:int, desativadas:int}
     */
    public static function sincronizar(iterable $condicoes): array
    {
        $linhas = [];
        $agora = now();

        foreach ($condicoes as $c) {
            $codigo = trim((string) $c['codigo']);
            if ($codigo === '') {
                continue;
            }
            $linhas[$codigo] = [
                'codigo' => $codigo,
                'descricao' => trim(preg_replace('/\s+/', ' ', (string) $c['descricao'])),
                'tipo' => isset($c['tipo']) ? trim((string) $c['tipo']) : null,
                'ativo' => true,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        DB::table('condicoes_pagamento')->upsert(array_values($linhas), ['codigo'], ['descricao', 'tipo', 'ativo', 'updated_at']);

        $desativadas = DB::table('condicoes_pagamento')
            ->whereNotIn('codigo', array_keys($linhas))
            ->where('ativo', true)
            ->update(['ativo' => false, 'updated_at' => $agora]);

        Cache::forget(self::CHAVE_OPCOES);
        self::$porDescricao = null;

        return ['gravadas' => count($linhas), 'desativadas' => $desativadas];
    }

    /** @return list<array{codigo:string, descricao:string, tipo:string}> */
    public static function doArquivo(): array
    {
        return json_decode((string) file_get_contents(base_path(self::ARQUIVO)), true)['condicoes'];
    }

    private const CHAVE_OPCOES = 'condicoes-pagamento:opcoes:v1';

    /**
     * As condições ativas para o seletor, as MAIS USADAS PRIMEIRO (uso real dos pedidos
     * do TOTVS nos últimos 12 meses). São 391 — sem ordem por uso, o "28 DDL" que metade
     * da equipe usa ficaria perdido entre "ESTAPAR VENC 15,25 DO MES SUBS" e afins.
     *
     * Cache de um dia: a contagem só serve para ordenar, e muda devagar.
     *
     * @return list<array{codigo:string, descricao:string, usos:int}>
     */
    public static function opcoes(): array
    {
        return Cache::remember(self::CHAVE_OPCOES, now()->addDay(), function () {
            $usos = DB::table('pedidos')
                ->where('data_pedido', '>=', now()->subYear()->toDateString())
                ->whereNotNull('condicao_pagamento')
                ->selectRaw('condicao_pagamento, COUNT(*) AS n')
                ->groupBy('condicao_pagamento')
                ->pluck('n', 'condicao_pagamento');

            return CondicaoPagamento::query()
                ->where('ativo', true)
                ->orderBy('codigo')
                ->get(['codigo', 'descricao'])
                ->map(fn (CondicaoPagamento $c) => [
                    'codigo' => $c->codigo,
                    'descricao' => $c->descricao,
                    'usos' => (int) ($usos[$c->codigo] ?? 0),
                ])
                ->sortByDesc('usos')
                ->values()
                ->all();
        });
    }

    public static function descricaoDe(?string $codigo): ?string
    {
        return $codigo === null ? null : CondicaoPagamento::whereKey($codigo)->value('descricao');
    }

    /**
     * Reconhece o TEXTO antigo do orçamento ("28/35/42DDL", "28 DDL", "30/60 Dias") e
     * devolve o código da condição com a mesma descrição — ou null se não houver uma
     * só leitura possível. É sugestão: o vendedor confirma na tela, nada é gravado aqui.
     *
     * Compara depois de normalizar os dois lados: maiúsculas, sem espaço, "DIAS" = "DDL",
     * zero à esquerda fora ("07 DDL" = "7DDL"), e número puro ganha "DDL" ("30/60").
     * Descrição repetida no Protheus ("60 DDL" é 060 e 349) fica com o menor código.
     */
    public static function sugerirCodigo(?string $texto): ?string
    {
        $alvo = self::normalizar($texto);

        if ($alvo === '') {
            return null;
        }

        /*
         * Mapa normalizado → código montado UMA vez por processo: a listagem chama isto
         * por linha. Ordenado por código, o primeiro a entrar vence as descrições
         * repetidas (o menor código).
         */
        if (self::$porDescricao === null) {
            self::$porDescricao = [];
            foreach (CondicaoPagamento::where('ativo', true)->orderBy('codigo')->get(['codigo', 'descricao']) as $c) {
                self::$porDescricao[self::normalizar($c->descricao)] ??= $c->codigo;
            }
        }

        return self::$porDescricao[$alvo] ?? null;
    }

    /** @var array<string, string>|null */
    private static ?array $porDescricao = null;

    public static function normalizar(?string $texto): string
    {
        $t = mb_strtoupper(trim((string) $texto));
        $t = preg_replace('/\bDIAS?\b/u', 'DDL', $t);
        $t = preg_replace('/\s+/', '', $t);
        $t = preg_replace('/(?<!\d)0+(?=\d)/', '', $t);

        if (preg_match('#^[\d/]+$#', $t)) {
            $t .= 'DDL';
        }

        return $t;
    }
}
