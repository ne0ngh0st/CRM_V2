<?php

namespace App\Services\Geografia;

/**
 * Mesorregiões do IBGE: de que região é cada município, que municípios cada região tem,
 * e o nome dela.
 *
 * ⚠️ A DIVISÃO vem do MESMO `public/geo/municipios.json` que o mapa carrega no navegador
 * (quinto elemento de cada entrada). É de propósito: a pill da região é contada aqui, o
 * filtro `?mesorregiao=` da lista também, e o contorno é desenhado lá. Com duas fontes, o
 * número da pill poderia sair de uma divisão e a lista aberta por ela de outra (Regra de
 * ouro nº 8). Para mudar a divisão, regenere o arquivo — ver `public/geo/README.md`.
 *
 * O NOME vem de `database/dados-bi/IBGE_MUNICIPIOS.csv`, a tabela de onde o arquivo acima
 * foi gerado. Nome é rótulo, não decide contagem.
 *
 * Os dois arquivos são lidos uma vez por processo (o PHP-FPM reaproveita o worker).
 */
class Mesorregioes
{
    /** @var array<int, int>|null */
    private static ?array $porMunicipio = null;

    /** @var array<int, list<int>>|null */
    private static ?array $municipiosPorMeso = null;

    /** @var array<int, string>|null */
    private static ?array $nomes = null;

    /** @return array<int, int> código do município => código da mesorregião */
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

    /**
     * Os municípios de uma mesorregião — é o que o filtro `?mesorregiao=` da lista aplica.
     * Região desconhecida devolve lista vazia.
     *
     * @return list<int>
     */
    public function municipiosDe(int $mesorregiao): array
    {
        if (self::$municipiosPorMeso === null) {
            self::$municipiosPorMeso = [];

            foreach ($this->porMunicipio() as $municipio => $meso) {
                self::$municipiosPorMeso[$meso][] = $municipio;
            }
        }

        return self::$municipiosPorMeso[$mesorregiao] ?? [];
    }

    public function nome(int $mesorregiao): ?string
    {
        if (self::$nomes === null) {
            self::$nomes = [];
            $arquivo = base_path('database/dados-bi/IBGE_MUNICIPIOS.csv');
            $linhas = is_file($arquivo) ? file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $cabecalho = array_map(fn ($c) => trim($c, "\"\u{FEFF} "), str_getcsv((string) array_shift($linhas), ';'));
            $iCod = array_search('cod_meso', $cabecalho, true);
            $iNome = array_search('nome_meso', $cabecalho, true);

            if ($iCod !== false && $iNome !== false) {
                foreach ($linhas as $linha) {
                    $c = str_getcsv($linha, ';');
                    self::$nomes[(int) $c[$iCod]] ??= $c[$iNome];
                }
            }
        }

        return self::$nomes[$mesorregiao] ?? null;
    }
}
