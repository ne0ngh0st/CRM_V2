<?php

namespace App\Services\ResumoEquipe;

/**
 * Formatação dos números do resumo diário por e-mail — um lugar só para o layout e as
 * partials (Regra de ouro nº 8).
 */
class FormatoResumo
{
    public function int(int|float|null $n): string
    {
        return number_format((int) $n, 0, ',', '.');
    }

    public function pct(int|float|null $n): string
    {
        if ($n === null) {
            return '—';
        }

        return number_format((float) $n, $n >= 100 ? 0 : 1, ',', '.').'%';
    }

    /**
     * Dinheiro compacto: e-mail é lido de relance, e "R$ 1,23 mi" se lê mais rápido que
     * "R$ 1.234.567,89". O valor exato está no CRM, a um clique.
     */
    public function reais(int|float|null $v): string
    {
        $v = (float) $v;
        $abs = abs($v);

        if ($abs >= 1_000_000) {
            return 'R$ '.number_format($v / 1_000_000, 2, ',', '.').' mi';
        }
        if ($abs >= 10_000) {
            return 'R$ '.number_format($v / 1_000, 0, ',', '.').' mil';
        }

        return 'R$ '.number_format($v, 0, ',', '.');
    }

    /**
     * Dinheiro cheio, sem centavos — para COLUNA de tabela. Numa coluna o olho compara
     * pelo comprimento do número; "R$ 77 mil" ao lado de "R$ 4.491" mistura escalas e
     * engana a comparação. No destaque (um número sozinho) vale o compacto de reais().
     */
    public function reaisCheio(int|float|null $v): string
    {
        return 'R$ '.number_format((float) $v, 0, ',', '.');
    }

    /** "sexta, 25/09" — o dia de venda/faturamento, sem o "-feira". */
    public function diaCurto(\DateTimeInterface $d): string
    {
        $c = \Illuminate\Support\Carbon::instance($d);

        return str_replace('-feira', '', $c->translatedFormat('l')).', '.$c->format('d/m');
    }

    /**
     * Tom do % da meta — as mesmas faixas do /metas (atingiu ≥ 100, quase ≥ 80, abaixo).
     *
     * @return array{0: string, 1: string, 2: string} [fundo, texto, borda]
     */
    public function tom(int|float|null $pct): array
    {
        return match (true) {
            $pct === null => ['#f4f4f5', '#71717a', '#d4d4d8'],
            $pct >= 100 => ['#ecfdf5', '#047857', '#10b981'],
            $pct >= 80 => ['#fffbeb', '#b45309', '#f59e0b'],
            default => ['#fef2f2', '#b91c1c', '#ef4444'],
        };
    }
}
