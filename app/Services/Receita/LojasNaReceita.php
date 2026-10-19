<?php

namespace App\Services\Receita;

use App\Models\Segmento;
use App\Services\Leads\SegmentoDosLeads;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Support\Facades\DB;

/**
 * Quantas lojas ativas uma empresa supermercadista tem na base da Receita.
 *
 * Conta estabelecimento com situação ATIVA e CNAE de loja do mapa de
 * `SegmentoDosLeads` (hiper, super, mercearia, atacado de alimentos, hortifrúti).
 * Depósito e escritório ficam de fora. A raiz só existe em memória durante a carga;
 * o total é gravado em cada CNPJ de 14 dígitos que já guardamos.
 */
class LojasNaReceita
{
    public static function eLoja(?string $codigoSituacao, ?string $cnae): bool
    {
        $digitos = preg_replace('/\D/', '', (string) $cnae);

        return SituacaoCadastral::deCodigo((string) $codigoSituacao) === SituacaoCadastral::ATIVA
            && isset(SegmentoDosLeads::MAPA[$digitos]);
    }

    /**
     * Raízes (8 dígitos) das empresas do segmento 101 e dos leads desse segmento.
     * Quem não tem CNPJ de 14 dígitos não entra — não há o que contar.
     *
     * @return array<string, true>
     */
    public static function raizes(): array
    {
        UniversoDoSegmento::criarSegmentoDaEmpresa();
        $empresas = DB::table('vdu_segmento_empresa')
            ->where('seg', Segmento::CODIGO_SUPERMERCADISTA)
            ->pluck('empresa');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS vdu_segmento_empresa');

        $raizes = [];
        foreach ($empresas as $empresa) {
            if (preg_match('/^\d{8}$/', (string) $empresa)) {
                $raizes[$empresa] = true;
            }
        }

        $nome = Segmento::query()->where('codigo', Segmento::CODIGO_SUPERMERCADISTA)->value('nome');
        if ($nome) {
            DB::table('leads')->where('segmento', $nome)->whereNotNull('cnpj')->orderBy('id')->select('cnpj')
                ->cursor()->each(function ($l) use (&$raizes) {
                    $digitos = preg_replace('/\D/', '', (string) $l->cnpj);
                    if (strlen($digitos) === 14) {
                        $raizes[substr($digitos, 0, 8)] = true;
                    }
                });
        }

        return $raizes;
    }

    /**
     * @param  array<string, int>  $contagem  raiz → lojas
     */
    public static function gravar(array $contagem): void
    {
        foreach ($contagem as $raiz => $total) {
            if (! preg_match('/^\d{8}$/', (string) $raiz)) {
                continue;
            }

            DB::table('cnpj_situacoes')->where('cnpj', 'like', $raiz.'%')->update([
                'lojas_receita' => (int) $total,
            ]);
        }
    }
}
