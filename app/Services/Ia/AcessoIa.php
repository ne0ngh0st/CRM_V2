<?php

namespace App\Services\Ia;

use App\Models\IaMensagem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Quem usa o assistente e quanto. Uma decisão, um lugar (Regra de ouro nº 8): o gate
 * `usar-ia`, o botão no layout e o endpoint perguntam aqui.
 */
final class AcessoIa
{
    /**
     * Admin sempre (é como se testa em produção com o interruptor desligado). Os demais,
     * só com o assistente habilitado e o e-mail na lista de beta testers.
     *
     * `$adminSimulando`: durante a simulação de usuário o guard devolve o simulado, mas
     * quem está do outro lado é um admin — ele precisa conseguir testar o assistente como
     * aquele vendedor, mesmo que o vendedor não esteja na lista.
     */
    public function podeUsar(?User $user, bool $adminSimulando = false): bool
    {
        if ($user === null) {
            return false;
        }

        if ($adminSimulando || $user->hasRole('admin')) {
            return true;
        }

        return config('ia.habilitado')
            && in_array(mb_strtolower((string) $user->email), config('ia.usuarios_liberados', []), true);
    }

    /** Perguntas feitas hoje por este usuário. */
    public function perguntasHoje(User $user): int
    {
        return IaMensagem::query()
            ->join('ia_conversas', 'ia_conversas.id', '=', 'ia_mensagens.conversa_id')
            ->where('ia_conversas.user_id', $user->id)
            ->where('ia_mensagens.papel', 'usuario')
            ->where('ia_mensagens.created_at', '>=', today())
            ->count();
    }

    /**
     * Mensagem para o usuário se ele não puder perguntar agora; null se pode.
     *
     * ⚠️ O teto mensal é somado com cache de 5 min — chave COM TTL, nunca
     * `Cache::forever` (ver a política do Redis no CLAUDE.md). Consequência aceita: o
     * teto pode ser ultrapassado em até 5 minutos de uso.
     */
    public function bloqueio(User $user): ?string
    {
        $gasto = (float) Cache::remember('ia:gasto:'.now()->format('Y-m'), now()->addMinutes(5), fn () => (float) IaMensagem::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('custo_usd'));

        if ($gasto >= (float) config('ia.teto_mensal_usd')) {
            return 'O assistente atingiu o limite de uso deste mês. Volta no mês que vem — se for urgente, fale com o suporte.';
        }

        if (! $user->hasRole('admin') && $this->perguntasHoje($user) >= (int) config('ia.limite_diario_por_usuario')) {
            return 'Você chegou ao limite de perguntas de hoje. Amanhã libera de novo.';
        }

        return null;
    }

    /**
     * Custo estimado em US$ pela tabela de `config('ia.precos')`. Modelo fora da tabela
     * (um fallback, por exemplo) usa o preço do modelo configurado — estimativa, não fatura.
     * Modelo local (`local:…`, Ollama na máquina do dev) não custa nada.
     */
    public function custo(string $modelo, int $entrada, int $saida, int $cacheLeitura, int $cacheEscrita): float
    {
        if (str_starts_with($modelo, 'local:') || $modelo === 'demonstracao') {
            return 0.0;
        }

        $precos = config('ia.precos');
        $p = $precos[$modelo] ?? $precos[config('ia.modelo')] ?? ['entrada' => 0, 'saida' => 0, 'cache_leitura' => 0, 'cache_escrita' => 0];

        return ($entrada * $p['entrada'] + $saida * $p['saida']
            + $cacheLeitura * $p['cache_leitura'] + $cacheEscrita * $p['cache_escrita']) / 1_000_000;
    }
}
