<?php

namespace App\Services\Carteira;

/**
 * Transforma "uma linha por (cliente, município)" nos três níveis que o mapa desenha:
 * município (bolha), estado (selo) e total.
 *
 * Existe separado do `CarteiraController::mapaDoEscopo()` — que monta a consulta — porque
 * os três níveis contam CLIENTE DISTINTO, e isso não se soma: um cliente com lojas em
 * Campinas e em Santos está nas duas bolhas, UMA vez no selo de SP e uma vez no total.
 * Em SQL seriam três agregações sobre a mesma base (cada uma ~400 ms no escopo empresa);
 * aqui é uma passada só sobre as ~56 mil linhas que a consulta já devolve.
 *
 * As invariantes que a tela depende, todas travadas em `CarteiraMapaTest`:
 *
 *   - `clientes` de um município == total da lista com `?municipio=`;
 *   - `clientes` de um estado    == total da lista com `?estado=`;
 *   - `totais.clientes`          == total da lista do recorte;
 *   - comerciais + entregas (municípios + sem localização) == filiais do recorte.
 */
class MapaDaCarteira
{
    /**
     * Código de UF do IBGE (os dois primeiros dígitos do código do município) → sigla.
     * É o que permite tirar o estado do próprio `cod_municipio`, sem outra consulta.
     */
    public const UF_POR_CODIGO = [
        11 => 'RO', 12 => 'AC', 13 => 'AM', 14 => 'RR', 15 => 'PA', 16 => 'AP', 17 => 'TO',
        21 => 'MA', 22 => 'PI', 23 => 'CE', 24 => 'RN', 25 => 'PB', 26 => 'PE', 27 => 'AL',
        28 => 'SE', 29 => 'BA', 31 => 'MG', 32 => 'ES', 33 => 'RJ', 35 => 'SP', 41 => 'PR',
        42 => 'SC', 43 => 'RS', 50 => 'MS', 51 => 'MT', 52 => 'GO', 53 => 'DF',
    ];

    private const ZERO = ['comerciais' => 0, 'entregas' => 0, 'clientes' => 0, 'ativos' => 0, 'inativando' => 0, 'inativos' => 0];

    /**
     * @param  iterable<object{cod: ?int, cod_cliente: string, comerciais: int, entregas: int}>  $porClienteMunicipio
     * @param  array<string, ?string>  $ultimaCompraPorCliente  `cod_cliente` => data (Y-m-d) consolidada no ESCOPO
     * @param  iterable<object{cod_cliente: string, estado: ?string, entrega: int}>  $filiaisSemCodigo
     *         filiais cujo município o de-para não resolveu: ficam fora das bolhas, mas o
     *         ESTADO delas é conhecido e entra no selo (senão o selo divergiria da lista)
     * @return array{municipios: list<array<string, int>>, estados: list<array<string, int|string>>, semLocalizacao: array<string, int>, totais: array<string, int>}
     */
    public function agregar(iterable $porClienteMunicipio, iterable $filiaisSemCodigo, array $ultimaCompraPorCliente, string $limiteAtivo, string $limiteInativando, string $soStatus = ''): array
    {
        /*
         * `$soStatus` é o `?status=` da tela. O filtro acontece AQUI, e não na consulta:
         * é a mesma regra da lista agrupada (a faixa da última compra CONSOLIDADA do
         * cliente), aplicada sobre um dado que o mapa já tem em mãos. Status desconhecido
         * não filtra nada, como na lista.
         */
        $soFaixa = ['ativo' => 'ativos', 'inativando' => 'inativando', 'inativo' => 'inativos'][$soStatus] ?? null;

        /*
         * O status é o do CLIENTE — a última compra consolidada no escopo, a mesma pill
         * da lista —, nunca o da filial daquela cidade.
         */
        $faixa = function (string $cliente) use ($ultimaCompraPorCliente, $limiteAtivo, $limiteInativando): string {
            $uc = $ultimaCompraPorCliente[$cliente] ?? null;

            return match (true) {
                $uc === null || $uc < $limiteInativando => 'inativos',
                $uc < $limiteAtivo => 'inativando',
                default => 'ativos',
            };
        };

        $municipios = [];
        $estados = [];
        $semLocal = self::ZERO;
        $clientes = [];
        $clientesPorUf = [];
        $comerciais = 0;
        $entregas = 0;

        // Conta o cliente UMA vez por estado, mesmo com lojas em várias cidades dele.
        $noEstado = function (?string $uf, string $cliente, string $status) use (&$estados, &$clientesPorUf): void {
            if ($uf === null) {
                return;
            }

            $estados[$uf] ??= self::ZERO;

            if (! isset($clientesPorUf[$uf][$cliente])) {
                $clientesPorUf[$uf][$cliente] = true;
                $estados[$uf]['clientes']++;
                $estados[$uf][$status]++;
            }
        };

        foreach ($porClienteMunicipio as $l) {
            $status = $faixa((string) $l->cod_cliente);

            if ($soFaixa !== null && $status !== $soFaixa) {
                continue;
            }

            $clientes[$l->cod_cliente] = true;
            $comerciais += (int) $l->comerciais;
            $entregas += (int) $l->entregas;

            if ($l->cod === null) {
                $semLocal['comerciais'] += (int) $l->comerciais;
                $semLocal['entregas'] += (int) $l->entregas;
                $semLocal['clientes']++;
                $semLocal[$status]++;

                continue;
            }

            $cod = (int) $l->cod;
            $municipios[$cod] ??= self::ZERO;
            $municipios[$cod]['comerciais'] += (int) $l->comerciais;
            $municipios[$cod]['entregas'] += (int) $l->entregas;
            $municipios[$cod]['clientes']++;
            $municipios[$cod][$status]++;

            $uf = self::UF_POR_CODIGO[intdiv($cod, 100000)] ?? null;

            if ($uf !== null) {
                $estados[$uf] ??= self::ZERO;
                $estados[$uf]['comerciais'] += (int) $l->comerciais;
                $estados[$uf]['entregas'] += (int) $l->entregas;
            }

            $noEstado($uf, (string) $l->cod_cliente, $status);
        }

        $siglas = array_flip(self::UF_POR_CODIGO);

        foreach ($filiaisSemCodigo as $f) {
            $uf = isset($siglas[$f->estado]) ? $f->estado : null;

            if ($uf === null) {
                continue; // "EX", vazio: não é estado do mapa
            }

            $status = $faixa((string) $f->cod_cliente);

            if ($soFaixa !== null && $status !== $soFaixa) {
                continue;
            }

            $estados[$uf] ??= self::ZERO;
            $estados[$uf][$f->entrega ? 'entregas' : 'comerciais']++;

            $noEstado($uf, (string) $f->cod_cliente, $status);
        }

        ksort($municipios);
        ksort($estados);

        return [
            'municipios' => array_map(fn ($cod) => ['cod' => $cod] + $municipios[$cod], array_keys($municipios)),
            'estados' => array_map(fn ($uf) => ['uf' => $uf] + $estados[$uf], array_keys($estados)),
            'semLocalizacao' => $semLocal,
            'totais' => ['clientes' => count($clientes), 'comerciais' => $comerciais, 'entregas' => $entregas],
        ];
    }
}
