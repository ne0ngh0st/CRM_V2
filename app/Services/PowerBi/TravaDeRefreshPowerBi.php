<?php

namespace App\Services\PowerBi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Quantas vezes, e com que espaçamento, o CRM pode pedir refresh ao Power BI.
 *
 * O Pro aceita 8 refreshes por dataset por dia. O `totvs:atualizar` roda de hora em
 * hora e o botão da tela pode ser clicado a qualquer momento: sem trava, um dia de
 * relatórios subindo de manhã e à tarde passaria de 8 antes do meio-dia, e o Serviço
 * passaria a recusar — inclusive o refresh manual que alguém tentasse pelo portal.
 *
 * Regra (config `powerbi.refresh`): no máximo `max_por_dia` disparos em qualquer janela
 * de 24 h, e pelo menos `intervalo_minutos` entre dois disparos.
 *
 * ⚠️ JANELA MÓVEL, não dia do calendário. A Microsoft não documenta com clareza em que
 * fuso o "dia" vira; contando as últimas 24 h, o limite vale para qualquer resposta.
 *
 * ⚠️ Estado no Redis, compartilhado pelos dois nós — o worker pode rodar em qualquer um.
 * Tudo com TTL (Cache::forever é proibido: o Redis descarta só chave com TTL, e uma
 * chave imortal aqui sobreviveria a tudo). Se o Redis for esvaziado, a trava "esquece"
 * os disparos do dia — o pior caso é o Serviço recusar um refresh, que aparece em
 * vermelho na tela, não dado errado.
 */
class TravaDeRefreshPowerBi
{
    private const CHAVE_DISPAROS = 'powerbi:refresh:disparos';

    private const CHAVE_PENDENTE = 'powerbi:refresh:pendente';

    private const CHAVE_LOCK = 'powerbi:refresh:lock';

    /**
     * Executa `$fn` com exclusividade entre workers: "posso disparar?" seguido de
     * "disparei" tem que ser atômico, senão dois workers passam pela mesma vaga.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function exclusivo(callable $fn): mixed
    {
        return Cache::lock(self::CHAVE_LOCK, 60)->block(15, $fn);
    }

    /**
     * Quando o próximo disparo fica liberado. `null` = pode disparar agora.
     */
    public function proximaJanela(?CarbonImmutable $agora = null): ?CarbonImmutable
    {
        $agora ??= CarbonImmutable::now();
        $disparos = $this->disparos($agora);
        $janelas = [];

        if (count($disparos) >= $this->maximo()) {
            // A vaga reabre quando o disparo mais antigo da janela sair dela.
            $janelas[] = $this->momento(min($disparos))->addDay();
        }

        if ($disparos !== []) {
            $janelas[] = $this->momento(max($disparos))->addMinutes($this->intervalo());
        }

        $proxima = $janelas === [] ? null : max($janelas);

        return $proxima !== null && $proxima->greaterThan($agora) ? $proxima : null;
    }

    public function registrar(?CarbonImmutable $agora = null): void
    {
        $agora ??= CarbonImmutable::now();
        $disparos = $this->disparos($agora);
        $disparos[] = $agora->getTimestamp();

        Cache::put(self::CHAVE_DISPAROS, $disparos, now()->addDays(2));
        Cache::forget(self::CHAVE_PENDENTE);
    }

    public function ultimoDisparo(): ?CarbonImmutable
    {
        $disparos = (array) Cache::get(self::CHAVE_DISPAROS, []);

        return $disparos === [] ? null : $this->momento(max($disparos));
    }

    public function disparosNasUltimas24h(?CarbonImmutable $agora = null): int
    {
        return count($this->disparos($agora ?? CarbonImmutable::now()));
    }

    /**
     * Marca que já existe um refresh adiado esperando a janela. `false` = já havia um,
     * e quem chamou não deve agendar outro.
     */
    public function marcarPendente(CarbonImmutable $para): bool
    {
        // TTL passa da janela com folga: se o job adiado se perder (Redis de fila
        // esvaziado), a marca some sozinha e a próxima importação agenda de novo.
        return Cache::add(self::CHAVE_PENDENTE, $para->getTimestamp(), $para->addHour());
    }

    public function pendentePara(): ?CarbonImmutable
    {
        $ts = Cache::get(self::CHAVE_PENDENTE);

        return $ts === null ? null : $this->momento((int) $ts);
    }

    public function limparPendente(): void
    {
        Cache::forget(self::CHAVE_PENDENTE);
    }

    /**
     * ⚠️ No fuso do app. `createFromTimestamp()` do Carbon 3 devolve UTC, e a mensagem
     * da tela diria "adiado para 12:30" para um refresh marcado para 09:30.
     */
    private function momento(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'));
    }

    public function maximo(): int
    {
        return max(1, (int) config('powerbi.refresh.max_por_dia'));
    }

    private function intervalo(): int
    {
        return max(0, (int) config('powerbi.refresh.intervalo_minutos'));
    }

    /** @return list<int> timestamps das últimas 24 h */
    private function disparos(CarbonImmutable $agora): array
    {
        $corte = $agora->subDay()->getTimestamp();

        return array_values(array_filter(
            (array) Cache::get(self::CHAVE_DISPAROS, []),
            fn ($ts) => (int) $ts > $corte,
        ));
    }
}
