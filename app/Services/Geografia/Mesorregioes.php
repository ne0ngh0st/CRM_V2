<?php

namespace App\Services\Geografia;

/**
 * Código IBGE do município → código da mesorregião dele.
 *
 * ⚠️ Lê o MESMO `public/geo/municipios.json` que o mapa carrega no navegador (quinto
 * elemento de cada entrada). É de propósito: o selo da mesorregião é contado aqui e o
 * contorno é desenhado lá, e com duas fontes o número de uma região poderia sair
 * calculado com uma divisão e desenhado com outra (Regra de ouro nº 8). Para mudar a
 * divisão, regenere o arquivo — ver `public/geo/README.md`.
 *
 * O arquivo tem ~280 KB e é lido uma vez por processo (o PHP-FPM reaproveita o worker).
 */
class Mesorregioes
{
    /** @var array<int, int>|null */
    private static ?array $porMunicipio = null;

    /** @return array<int, int> */
    public function porMunicipio(): array
    {
        if (self::$porMunicipio !== null) {
            return self::$porMunicipio;
        }

        $arquivo = public_path('geo/municipios.json');
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        $mapa = [];

        foreach (is_array($dados) ? $dados : [] as $cod => $municipio) {
            if (isset($municipio[4])) {
                $mapa[(int) $cod] = (int) $municipio[4];
            }
        }

        return self::$porMunicipio = $mapa;
    }
}
