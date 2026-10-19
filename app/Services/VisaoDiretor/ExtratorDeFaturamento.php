<?php

namespace App\Services\VisaoDiretor;

/**
 * Tira de um texto o faturamento anual que a página declara, com o ano.
 *
 * Não estima. Capital social, média de setor e número sem ano não viram valor — a
 * planilha deixa a célula vazia, e vazio não é zero.
 */
class ExtratorDeFaturamento
{
    /**
     * @param  list<array{url?: string, title?: string, description?: string}>  $resultados
     * @return array{valor: int, ano: int, url: string}|null
     */
    public function extrair(array $resultados): ?array
    {
        $melhor = null;

        foreach ($resultados as $r) {
            $texto = trim(($r['title'] ?? '').' '.($r['description'] ?? ''));
            $achado = $this->doTexto($texto);
            if ($achado === null) {
                continue;
            }
            if ($melhor === null || $achado['ano'] > $melhor['ano']) {
                $melhor = [...$achado, 'url' => (string) ($r['url'] ?? '')];
            }
        }

        return $melhor;
    }

    /** @return array{valor: int, ano: int}|null */
    public function doTexto(string $texto): ?array
    {
        $melhor = null;

        foreach (preg_split('/(?<=[.!?;])\s+/u', $texto) ?: [] as $frase) {
            if (! preg_match('/faturamento|receita bruta|faturou/ui', $frase)) {
                continue;
            }
            if (preg_match('/capital social|m[eé]dia do setor|faturamento m[eé]dio|ticket m[eé]dio/ui', $frase)) {
                continue;
            }
            if (! preg_match_all('/\b(19|20)\d{2}\b/', $frase, $anos)) {
                continue;
            }
            $ano = max(array_map('intval', $anos[0]));
            if ($ano < 1990 || $ano > (int) date('Y') + 1) {
                continue;
            }
            if (! preg_match_all('/(?:R\$\s*)?(\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+[.,]\d+|\d+)\s*(bilh(?:ões|oes|ão|ao)|milh(?:ões|oes|ão|ao)|mil|bi|mi)?\b/ui', $frase, $todos, PREG_SET_ORDER)) {
                continue;
            }
            $valor = null;
            foreach ($todos as $m) {
                $escala = ($m[2] ?? '') !== '' ? $m[2] : null;
                $temCifrao = str_contains(mb_strtoupper($m[0]), 'R$');
                // O ano da frase também é um número. Sem R$ e sem escala, não é faturamento.
                if (! $temCifrao && $escala === null) {
                    continue;
                }
                $candidato = $this->reais($m[1], $escala);
                if ($candidato !== null) {
                    $valor = $candidato;
                    break;
                }
            }
            if ($valor === null) {
                continue;
            }
            if ($melhor === null || $ano > $melhor['ano']) {
                $melhor = ['valor' => $valor, 'ano' => $ano];
            }
        }

        return $melhor;
    }

    private function reais(string $numero, ?string $escala): ?int
    {
        $multiplicador = match (true) {
            $escala !== null && preg_match('/^bi/ui', $escala) => 1_000_000_000,
            $escala !== null && preg_match('/^(milh|mi$)/ui', $escala) => 1_000_000,
            $escala !== null && preg_match('/^mil/ui', $escala) => 1_000,
            default => 1,
        };

        if (str_contains($numero, ',')) {
            $n = (float) str_replace(['.', ','], ['', '.'], $numero);
        } elseif ($multiplicador > 1 && preg_match('/^\d+\.\d{1,2}$/', $numero)) {
            $n = (float) $numero;
        } else {
            $n = (float) str_replace('.', '', $numero);
        }

        $reais = (int) round($n * $multiplicador);

        return $reais >= 1000 ? $reais : null;
    }
}
