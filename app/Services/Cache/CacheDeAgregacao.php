<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Camada fina sobre o cache para as agregações caras do sistema.
 *
 * Existe por dois motivos, nenhum deles "abstrair o Laravel":
 *
 * 1. O TTL mora num lugar só (`config('perf.ttl_agregacao_minutos')`), e precisa combinar
 *    com o intervalo do job de warming. Espalhado em `now()->addMinutes(15)` por bloco,
 *    ajustar essa relação viraria caça a literais.
 * 2. `aquecer()` e `lembrar()` compartilham a MESMA chave e o MESMO cálculo por
 *    construção — é o que garante que o job de warming grave exatamente onde o
 *    controller vai ler.
 *
 * ⚠️ `Cache::forever()` é proibido neste projeto: o Redis roda com
 * `maxmemory-policy volatile-lru`, que só descarta chave COM prazo de validade. Chave
 * sem TTL fica imune ao descarte e imortal. Por isso todo método daqui grava com TTL.
 */
class CacheDeAgregacao
{
    /** Lê do cache ou calcula e guarda. É o caminho normal, usado pelos controllers. */
    public function lembrar(string $chave, Closure $calcular): mixed
    {
        return Cache::remember($chave, $this->ttl(), $calcular);
    }

    /**
     * Recalcula e sobrescreve, ignorando o que estiver lá. É o caminho do job de warming.
     *
     * Note que NÃO é `forget()` + `remember()`: isso abriria uma janela em que a chave
     * não existe, e quem chegasse nesse intervalo pagaria a agregação inteira — que é
     * exatamente o problema que o warming existe para eliminar.
     */
    public function aquecer(string $chave, Closure $calcular): mixed
    {
        $valor = $calcular();

        Cache::put($chave, $valor, $this->ttl());

        return $valor;
    }

    public function esquecer(string $chave): void
    {
        Cache::forget($chave);
    }

    /**
     * TTL das agregações. Precisa ser folgadamente maior que o intervalo do warming
     * (hoje 30 min de TTL contra 10 min de job — margem de 3x), para que uma rodada
     * perdida do worker não deixe a chave expirar na cara de um usuário.
     */
    private function ttl(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('perf.ttl_agregacao_minutos', 30));
    }
}
