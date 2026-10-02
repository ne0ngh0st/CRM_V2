<?php

namespace App\Services\Email;

use App\Models\EmailEnviado;
use App\Models\User;
use App\Services\Notificacao\NotificacaoService;
use Carbon\CarbonImmutable;

/**
 * Cota mensal do SMTP (smtplw: 500 envios por mês). Único lugar que sabe o limite,
 * o que conta como envio e quando avisar.
 *
 * - Conta só `status = enviado`: o que o SMTP recusou não consumiu cota.
 * - Conta MENSAGEM, não destinatário (uma linha de `emails_enviados` = um envio).
 * - Mês civil. Se o provedor renovar em outro dia, o número sai deslocado.
 *
 * ⚠️ Só enxerga o que saiu DESTE ambiente. O `.env` de dev usa a mesma conta SMTP, então
 * e-mail disparado no dev consome cota e não aparece na contagem de produção.
 */
class CotaDeEmails
{
    /** Percentuais que geram aviso no sino dos admins (uma vez por mês cada). */
    public const MARCOS = [80, 100];

    /** Antes disso a projeção é ruído (um dia cheio no dia 2 projeta 15x). */
    public const DIAS_MINIMOS_PROJECAO = 5;

    public static function limite(): int
    {
        return max(0, (int) config('mail.cota_mensal', 500));
    }

    public static function enviadosNoMes(?CarbonImmutable $agora = null): int
    {
        $agora ??= CarbonImmutable::now();

        return EmailEnviado::query()
            ->where('status', 'enviado')
            ->where('created_at', '>=', $agora->startOfMonth())
            ->where('created_at', '<', $agora->startOfMonth()->addMonth())
            ->count();
    }

    /**
     * O retrato do mês para a tela. `nivel`: ok | atencao (≥ 80% ou projeção estoura)
     * | esgotada (≥ 100%).
     */
    public static function resumo(?CarbonImmutable $agora = null): array
    {
        $agora ??= CarbonImmutable::now();
        $limite = self::limite();
        $enviados = self::enviadosNoMes($agora);
        $percentual = $limite > 0 ? round($enviados / $limite * 100, 1) : 0.0;

        $diasNoMes = $agora->daysInMonth;
        $diasDecorridos = $agora->day; // o dia de hoje já conta como decorrido
        $projecao = $diasDecorridos >= self::DIAS_MINIMOS_PROJECAO
            ? (int) round($enviados / $diasDecorridos * $diasNoMes)
            : null;

        $nivel = match (true) {
            $limite > 0 && $enviados >= $limite => 'esgotada',
            $percentual >= self::MARCOS[0], $projecao !== null && $limite > 0 && $projecao > $limite => 'atencao',
            default => 'ok',
        };

        return [
            'limite' => $limite,
            'enviados' => $enviados,
            'restantes' => max(0, $limite - $enviados),
            'percentual' => $percentual,
            'projecao' => $projecao,
            'diasRestantes' => $diasNoMes - $diasDecorridos,
            'nivel' => $nivel,
            'mes' => $agora->translatedFormat('F/Y'),
            'renovaEm' => $agora->startOfMonth()->addMonth()->format('d/m'),
        ];
    }

    /**
     * Chamado a cada envio: se a contagem cruzou um marco, avisa os admins. Idempotente
     * pela referência (`AAAAMM` × marco), então o aviso sai uma vez por mês por marco.
     */
    public static function avisarSeCruzouMarco(?CarbonImmutable $agora = null): void
    {
        $agora ??= CarbonImmutable::now();
        $limite = self::limite();
        if ($limite === 0) {
            return;
        }

        $enviados = self::enviadosNoMes($agora);
        $cruzados = array_filter(self::MARCOS, fn (int $m) => $enviados >= (int) ceil($limite * $m / 100));
        if ($cruzados === []) {
            return;
        }

        $marco = max($cruzados);
        $titulo = $marco >= 100
            ? "Cota de e-mail esgotada: {$enviados} de {$limite} no mês"
            : "Cota de e-mail em {$marco}%: {$enviados} de {$limite} no mês";
        $mensagem = $marco >= 100
            ? 'O SMTP pode recusar os próximos envios até a renovação ('.$agora->startOfMonth()->addMonth()->format('d/m').').'
            : 'Restam '.($limite - $enviados).' envios até o fim do mês.';

        $notificacoes = app(NotificacaoService::class);
        foreach (User::query()->where('is_active', true)->role('admin')->get() as $admin) {
            $notificacoes->notificar(
                $admin,
                'cota_email',
                $titulo,
                $mensagem,
                route('emails.index', absolute: false),
                'cota_email',
                ((int) $agora->format('Ym')) * 1000 + $marco,
            );
        }
    }
}
