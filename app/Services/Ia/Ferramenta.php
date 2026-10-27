<?php

namespace App\Services\Ia;

/**
 * Uma coisa que o assistente pode fazer — sempre COMO o usuário que perguntou.
 *
 * ⚠️ O escopo é responsabilidade de cada ferramenta, e tem que vir dos resolvers que as
 * telas já usam (`DashboardScopeResolver`, `MetaRankingResolver`…), nunca de uma regra
 * escrita aqui. É o que garante que o número dito no chat é o mesmo da tela, e que o
 * assistente não vira porta lateral para a carteira de outro vendedor.
 *
 * ⚠️ O que a ferramenta devolve vai para o modelo: devolver só o necessário. Dado de
 * terceiro em texto livre (observação de lead, mensagem do formulário do site) não entra
 * na Fase 1 — é por onde chegaria uma injeção de prompt.
 */
interface Ferramenta
{
    /** Nome que o modelo usa para chamar (snake_case, estável: está no histórico gravado). */
    public function nome(): string;

    /** O que aparece no painel enquanto ela roda ("Consultando pedido…"). */
    public function rotuloProgresso(): string;

    /**
     * Descrição para o modelo: QUANDO usar, não só o que faz.
     */
    public function descricao(): string;

    /**
     * JSON Schema da entrada. Todo campo em `required`, `additionalProperties: false`
     * (exigência do `strict: true`); opcional é `["string", "null"]`.
     *
     * @return array<string, mixed>
     */
    public function esquema(): array;

    /**
     * @param  array<string, mixed>  $entrada  já validada pela API contra o esquema
     * @return array<string, mixed>
     */
    public function executar(ContextoIa $contexto, array $entrada): array;
}
