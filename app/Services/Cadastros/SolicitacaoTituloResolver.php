<?php

namespace App\Services\Cadastros;

/**
 * Título TOTVS das solicitações de cadastro.
 *
 * Réplica fiel das regras do legado — o time de Cadastro usa esse título
 * literalmente pra abrir o item no TOTVS, então formato aqui não é escolha
 * estética. Fontes:
 *   - bobina:   includes/solicitacoes/bobina_titulo.php
 *   - etiqueta: includes/ajax/solicitacoes_etiquetas.php
 *               (gerar_titulo_padronizado_etiqueta)
 * Se mudar lá, mudar aqui.
 */
class SolicitacaoTituloResolver
{
    /**
     * Nomenclatura por gramatura (padrão do Cadastro). Tem precedência sobre
     * o campo `papel`: o legado só cai no papel quando a gramatura não mapeia.
     */
    private const NOMENCLATURA_POR_GRAMATURA = [
        '44' => 'TS KPH BC',
        '48' => 'TERMICO',
        '55' => 'TERMOSCRIPT',
    ];

    private const NOME_PAPEL = [
        'termicco' => 'TERMICO',
        'termoscript' => 'TERMOSCRIPT',
        'kpr' => 'KPR',
        'termobank' => 'TERMOBANK',
        'termoticket' => 'TERMOTICKET',
    ];

    public function bobina(
        string $nomenclatura,
        ?string $papel,
        ?string $largura,
        $metragem,
        ?string $gramatura = null,
    ): string {
        $partes = ['BOBINA'];

        $tipoPapel = $this->nomenclaturaPorGramatura($gramatura) ?? $this->nomePapel($papel);

        if ($tipoPapel) {
            $partes[] = $tipoPapel;
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

        return $this->normalizar($partes);
    }

    /**
     * Ex.: ETIQUETA SEGURANCA LATERAIS 40X40X30M BARONESA - TERMICO BORRACHA COM BARREIRA
     *
     * ⚠️ A saída de rolo (F1–F4) NÃO entra no título — fica na arte. O campo
     * continua existindo no cadastro do v2, só não compõe o nome do item.
     */
    public function etiqueta(
        string $nomenclatura,
        ?string $medidas,
        ?string $tipoAdesivo,
        $metragem = null,
    ): string {
        $partes = ['ETIQUETA'];
        $descricaoMedidas = '';
        $dimensoes = '';

        if ($medidas) {
            $medidasNorm = trim(preg_replace('/\s+/', ' ', $medidas) ?? $medidas);

            // "40X40 SEGURANÇA LATERAIS" → dimensão "40X40" + descrição "SEGURANÇA LATERAIS",
            // que no título trocam de lugar (descrição antes, dimensão depois).
            if (preg_match('/^(\d+(?:[.,]\d+)?\s*[Xx]\s*\d+(?:[.,]\d+)?)(?:\s+(.+))?$/u', $medidasNorm, $m)) {
                $dimensoes = strtoupper(preg_replace('/\s+/', '', str_replace(',', '.', $m[1])) ?? $m[1]);
                $descricaoMedidas = isset($m[2]) && trim($m[2]) !== ''
                    ? mb_strtoupper(trim($m[2]), 'UTF-8')
                    : '';
            } else {
                $descricaoMedidas = mb_strtoupper($medidasNorm, 'UTF-8');
            }
        }

        if ($descricaoMedidas !== '') {
            $partes[] = $descricaoMedidas;
        }

        $metragemFmt = $this->formatDimension($metragem);

        if ($dimensoes !== '' && $metragemFmt !== null) {
            $partes[] = $dimensoes.'X'.$metragemFmt.'M';
        } elseif ($dimensoes !== '') {
            $partes[] = $dimensoes;
        } elseif ($metragemFmt !== null) {
            $partes[] = $metragemFmt.'M';
        }

        $partes[] = trim($nomenclatura);

        $titulo = $this->normalizar($partes);

        // Tipo de adesivo é sufixo, separado por " - " — não entra no meio do nome.
        if ($tipo = $this->tipoAdesivo($tipoAdesivo)) {
            $titulo .= ' - '.$tipo;
        }

        return $titulo;
    }

    private function nomenclaturaPorGramatura(?string $gramatura): ?string
    {
        if ($gramatura === null || $gramatura === '') {
            return null;
        }

        return self::NOMENCLATURA_POR_GRAMATURA[(string) $gramatura] ?? null;
    }

    private function nomePapel(?string $papel): ?string
    {
        if (! $papel) {
            return null;
        }

        return self::NOME_PAPEL[strtolower($papel)] ?? strtoupper($papel);
    }

    private function tipoAdesivo(?string $tipo): ?string
    {
        if (! $tipo) {
            return null;
        }

        return mb_strtoupper(trim($tipo), 'UTF-8');
    }

    /** @param  list<string>  $partes */
    private function normalizar(array $partes): string
    {
        $juntas = implode(' ', array_filter($partes));

        return trim(preg_replace('/\s+/', ' ', $juntas) ?? $juntas);
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
