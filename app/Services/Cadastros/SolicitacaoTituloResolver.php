<?php

namespace App\Services\Cadastros;

class SolicitacaoTituloResolver
{
    public function bobina(string $nomenclatura, ?string $papel, ?string $largura, $metragem): string
    {
        $partes = ['BOBINA'];

        $papelMap = [
            'termicco' => 'TERMICO',
            'termoscript' => 'TERMOSCRIPT',
            'kpr' => 'KPR',
            'termobank' => 'TERMOBANK',
            'termoticket' => 'TERMOTICKET',
        ];

        if ($papel) {
            $partes[] = $papelMap[strtolower($papel)] ?? strtoupper($papel);
        }

        $larguraFmt = $this->formatDimension($largura);
        $metragemFmt = $this->formatDimension($metragem);

        if ($larguraFmt && $metragemFmt) {
            $partes[] = sprintf('%sX%sM', $larguraFmt, $metragemFmt);
        } elseif ($larguraFmt) {
            $partes[] = sprintf('%sMM', $larguraFmt);
        } elseif ($metragemFmt) {
            $partes[] = sprintf('%sM', $metragemFmt);
        }

        $partes[] = trim($nomenclatura);

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($partes))) ?? '');
    }

    public function etiqueta(string $nomenclatura, ?string $medidas, ?string $tipoAdesivo, ?string $saidaRolo): string
    {
        $partes = ['ETIQUETA'];

        if ($medidas) {
            $partes[] = strtoupper(preg_replace('/\s+/', '', $medidas) ?? $medidas);
        }

        if ($tipoAdesivo) {
            $partes[] = mb_strtoupper(trim($tipoAdesivo));
        }

        if ($saidaRolo && in_array(strtolower($saidaRolo), ['f1', 'f2', 'f3', 'f4'], true)) {
            $partes[] = strtoupper($saidaRolo);
        }

        $partes[] = trim($nomenclatura);

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($partes))) ?? '');
    }

    private function formatDimension(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = is_numeric($value)
            ? (float) $value
            : (float) str_replace(',', '.', (string) $value);

        if ($numeric <= 0) {
            return null;
        }

        $formatted = rtrim(rtrim(sprintf('%.2f', $numeric), '0'), '.');

        return $formatted !== '' ? $formatted : (string) (int) round($numeric);
    }
}
