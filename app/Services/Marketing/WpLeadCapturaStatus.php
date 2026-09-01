<?php

namespace App\Services\Marketing;

use App\Models\MarketingWpFormulario;
use App\Models\MarketingWpLeadRaw;
use App\Models\VendedorPerfil;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * O que a /leads mostra sobre a captura do site — ligado, último, dono, e o
 * que ficou pendente ou travado.
 *
 * ⚠️ Isto roda em TODA abertura da /leads, então tem que ser barato para
 * sempre, não só enquanto a tabela é pequena. Duas decisões cuidam disso:
 *
 * 1. Nada de `SUM(condicao)` sobre a tabela inteira. A versão anterior fazia
 *    `SUM(recebido_em >= ?)`, que lê todas as linhas para contar as de hoje —
 *    numa staging que só cresce, isso viraria full scan permanente (Regra de
 *    ouro nº 6). Agora é um COUNT com WHERE, que usa o índice de recebido_em.
 * 2. Cache curto. O TTL é de 30s e é invalidado de propósito quando alguém
 *    dispara o teste, para o botão não parecer quebrado.
 */
class WpLeadCapturaStatus
{
    private const CHAVE = 'leads:wp-captura-status';

    private const TTL_SEGUNDOS = 30;

    /**
     * @return array{
     *     ligado: bool,
     *     url: string,
     *     ultimoRecebidoEm: ?string,
     *     hoje: int,
     *     pendentes: int,
     *     travadas: int,
     *     donoCod: ?string,
     *     donoNome: ?string,
     *     podeTestar: bool
     * }
     */
    public function resumir(bool $podeTestar): array
    {
        $base = Cache::remember(self::CHAVE, self::TTL_SEGUNDOS, fn () => $this->calcular());

        return $base + ['podeTestar' => $podeTestar];
    }

    /** Chamado depois do teste interno, para o resultado aparecer na hora. */
    public function esquecer(): void
    {
        Cache::forget(self::CHAVE);
    }

    /**
     * @return array<string, mixed>
     */
    private function calcular(): array
    {
        $inicioHoje = Carbon::now(WpLeadIngestor::TZ)->startOfDay()->format('Y-m-d H:i:s');

        $ultimo = MarketingWpLeadRaw::query()->max('recebido_em');
        $hoje = MarketingWpLeadRaw::query()->where('recebido_em', '>=', $inicioHoje)->count();
        $pendentes = MarketingWpLeadRaw::query()->pendentes()->count();
        $travadas = MarketingWpLeadRaw::query()->travadas()->count();

        [$donoCod, $donoNome] = $this->donoPadrao();

        return [
            'ligado' => trim((string) config('marketing.wp_webhook_secret')) !== '',
            'url' => url('/webhooks/wordpress-leads'),
            'ultimoRecebidoEm' => $ultimo
                ? Carbon::parse($ultimo, WpLeadIngestor::TZ)->format('d/m/Y H:i')
                : null,
            'hoje' => $hoje,
            'pendentes' => $pendentes,
            'travadas' => $travadas,
            'donoCod' => $donoCod,
            'donoNome' => $donoNome,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function donoPadrao(): array
    {
        $padrao = MarketingWpFormulario::query()
            ->where('identificador', MarketingWpFormulario::IDENTIFICADOR_PADRAO)
            ->where('ativo', true)
            ->first();

        $codVendedor = $padrao?->cod_vendedor;
        if (! $codVendedor) {
            return [null, null];
        }

        $user = VendedorPerfil::query()
            ->where('cod_vendedor', $codVendedor)
            ->with('user:id,name,display_name')
            ->first()
            ?->user;

        return [$codVendedor, $user?->display_name ?: $user?->name];
    }
}
