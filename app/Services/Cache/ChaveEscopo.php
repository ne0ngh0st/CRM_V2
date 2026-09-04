<?php

namespace App\Services\Cache;

use Illuminate\Support\Carbon;

/**
 * Monta a chave de cache de uma agregação escopada.
 *
 * ⚠️ POR QUE ISTO EXISTE (Regra de ouro nº 8):
 * até 2026-08-27 cada bloco do Dashboard montava a própria chave com
 * `implode(',', $codVendedores)`, **sem ordenar** — e um deles (orcamentosStats) fazia
 * `sort()+md5()` por conta própria. Enquanto só o controller lia e escrevia, ninguém
 * notava. Com o job de cache warming (Fase 3) escrevendo as mesmas chaves, essa
 * divergência vira um bug mudo: o job grava `a,b`, o controller lê `b,a`, o cache nunca
 * acerta e o aquecimento simplesmente não faz nada — sem erro, sem log, sem sintoma
 * além de "continua lento".
 *
 * Com a montagem num lugar só, isso deixa de ser possível por construção.
 */
final readonly class ChaveEscopo
{
    /**
     * Muda quando o FORMATO dos dados cacheados muda de forma incompatível.
     *
     * Bumpar aqui invalida tudo de uma vez, sem precisar de flush: as chaves novas
     * simplesmente não colidem com as antigas, e as velhas expiram sozinhas pelo TTL.
     *
     * Histórico:
     *   v1 → v2 (2026-09-04) — `meta-gauge` passou a devolver `{venda, faturamento}` no
     *   lugar de `{mes, ano}`. Sem o bump, um payload v1 ainda quente seria entregue ao
     *   front novo, que leria `metaGauge.venda` como `undefined` e quebraria o card no
     *   navegador — durante os 30 minutos de TTL logo após o deploy, exatamente quando
     *   ninguém está olhando para o console de quem já estava logado.
     */
    public const VERSAO = 'v2';

    private const PREFIXO = 'agg';

    /** Acima disso a assinatura vira hash — chave gigante é ruim de ler e de armazenar. */
    private const LIMITE_LEGIVEL = 80;

    private function __construct(private string $assinatura) {}

    /**
     * Escopo por código de vendedor. `null` = empresa inteira (admin/diretor sem filtro).
     *
     * @param  array<string>|null  $codVendedores
     */
    public static function deCodVendedores(?array $codVendedores): self
    {
        return new self(self::normalizar($codVendedores, 'cv'));
    }

    /**
     * Escopo por id de usuário — usado pelos blocos que agregam por `user_id`
     * (ligações, observações, orçamentos), já que cod_vendedor pode ser compartilhado.
     *
     * @param  array<int>|null  $usuarioIds
     */
    public static function deUsuarioIds(?array $usuarioIds): self
    {
        return new self(self::normalizar($usuarioIds, 'uid'));
    }

    /**
     * Chave estável para um bloco.
     *
     * @param  array<string, scalar>  $extras  discriminadores adicionais (ex.: ['ano' => 2026])
     */
    public function para(string $bloco, array $extras = []): string
    {
        $partes = [self::PREFIXO, self::VERSAO, $bloco];

        ksort($extras);
        foreach ($extras as $nome => $valor) {
            $partes[] = "{$nome}={$valor}";
        }

        $partes[] = $this->assinatura;

        return implode(':', $partes);
    }

    /**
     * Igual a `para()`, mas com a data de hoje embutida.
     *
     * ⚠️ Obrigatório para qualquer agregação que dependa de `now()` por dentro — como
     * `carteiraSegmento` (limiares de 290/365 dias desde a última compra) e
     * `pedidosAtencao` (atrasado/vencendo em 7 dias). Sem a data na chave, um valor
     * calculado hoje continuaria válido amanhã com os limiares errados. Com TTL de 15
     * minutos isso era invisível; com o TTL de 30 minutos da Fase 3, não seria.
     *
     * @param  array<string, scalar>  $extras
     */
    public function paraDoDia(string $bloco, array $extras = []): string
    {
        return $this->para($bloco, $extras + ['d' => Carbon::now()->toDateString()]);
    }

    /**
     * Ordem, duplicata e tipo não podem mudar a chave: `['b','a']`, `['a','b']` e
     * `['a','b','a']` descrevem o mesmo escopo e precisam gerar a mesma string.
     *
     * @param  array<int|string>|null  $valores
     */
    private static function normalizar(?array $valores, string $tipo): string
    {
        if ($valores === null) {
            return "{$tipo}:todos";
        }

        if ($valores === []) {
            return "{$tipo}:nenhum";
        }

        $normalizados = array_map('strval', $valores);
        $normalizados = array_values(array_unique($normalizados));
        sort($normalizados);

        $lista = implode(',', $normalizados);

        return strlen($lista) > self::LIMITE_LEGIVEL
            ? "{$tipo}:h:".md5($lista)
            : "{$tipo}:{$lista}";
    }
}
