<?php

namespace App\Services\Etiquetas;

/**
 * Modelo de custo por m² pra precificação de etiqueta — admin-only (expõe custo/margem
 * interno). Preço de venda é sempre digitado pelo admin, nunca imposto pela margem
 * sugerida (30%), que é só uma referência.
 */
class EtiquetaPrecificadorService
{
    private const MARGEM_SUGERIDA = 0.30;

    /**
     * @param  array{largura_util: float, gap_lateral: float, altura_util: float, gap_supinf: float, metros_rolo: float, qtd_etiquetas: float, preco_m2_materia_prima: float, preco_venda?: ?float}  $dados
     * @return array{larguraTotalM: float, alturaTotalM: float, m2PorEtiqueta: float, labelsPorRolo: float, metrosNecessarios: float, m2TotalConsumido: float, custoTotal: float, precoSugerido: float, margemBrutaPct: ?float}
     */
    public function calcular(array $dados): array
    {
        $larguraTotalM = ($dados['largura_util'] + $dados['gap_lateral']) / 1000;
        $alturaTotalM = ($dados['altura_util'] + $dados['gap_supinf']) / 1000;

        $m2PorEtiqueta = $larguraTotalM * $alturaTotalM;
        $labelsPorRolo = $alturaTotalM > 0 ? $dados['metros_rolo'] / $alturaTotalM : 0.0;
        $metrosNecessarios = $dados['qtd_etiquetas'] * $alturaTotalM;
        $m2TotalConsumido = $larguraTotalM * $dados['metros_rolo'];

        $custoTotal = $dados['preco_m2_materia_prima'] * $m2TotalConsumido;
        $precoSugerido = $custoTotal / (1 - self::MARGEM_SUGERIDA);

        $precoVenda = $dados['preco_venda'] ?? null;
        $margemBrutaPct = ($precoVenda !== null && $precoVenda > 0)
            ? round((($precoVenda - $custoTotal) / $precoVenda) * 100, 2)
            : null;

        return [
            'larguraTotalM' => round($larguraTotalM, 4),
            'alturaTotalM' => round($alturaTotalM, 4),
            'm2PorEtiqueta' => round($m2PorEtiqueta, 6),
            'labelsPorRolo' => round($labelsPorRolo, 2),
            'metrosNecessarios' => round($metrosNecessarios, 2),
            'm2TotalConsumido' => round($m2TotalConsumido, 4),
            'custoTotal' => round($custoTotal, 2),
            'precoSugerido' => round($precoSugerido, 2),
            'margemBrutaPct' => $margemBrutaPct,
        ];
    }
}
