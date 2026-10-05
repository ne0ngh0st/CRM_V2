<?php

namespace App\Services\Receita;

/**
 * Porte da empresa na Receita — o único lugar que traduz o código da base aberta e o
 * texto das APIs do cartão para um valor só, e que dá o rótulo da tela (Regra de ouro
 * nº 8: o front não tem mapa, recebe o rótulo pronto).
 *
 * Gravado em `cnpj_situacoes.porte` como ME, EPP ou DEMAIS. "DEMAIS" é o que a Receita
 * chama de tudo que não é micro nem pequena — inclui empresa grande, mas também quem
 * nunca se enquadrou. Por isso o rótulo diz "Demais portes" e não "Grande".
 */
class PorteEmpresa
{
    public const ME = 'ME';

    public const EPP = 'EPP';

    public const DEMAIS = 'DEMAIS';

    /** Coluna PORTE dos arquivos de Empresas. "00" = não informado. */
    private const CODIGOS = ['01' => self::ME, '03' => self::EPP, '05' => self::DEMAIS];

    private const ROTULOS = [
        self::ME => 'Microempresa',
        self::EPP => 'Pequeno porte',
        self::DEMAIS => 'Demais portes',
    ];

    public static function deCodigo(?string $codigo): ?string
    {
        return self::CODIGOS[str_pad(trim((string) $codigo), 2, '0', STR_PAD_LEFT)] ?? null;
    }

    /**
     * Texto das APIs: BrasilAPI/minhareceita mandam "MICRO EMPRESA", "EMPRESA DE PEQUENO
     * PORTE", "DEMAIS"; a CNPJá manda o mesmo em caixa mista, às vezes a sigla.
     */
    public static function deTexto(?string $texto): ?string
    {
        $t = mb_strtoupper(trim((string) $texto));

        return match (true) {
            $t === 'ME', str_contains($t, 'MICRO') => self::ME,
            $t === 'EPP', str_contains($t, 'PEQUENO') => self::EPP,
            str_contains($t, 'DEMAIS') => self::DEMAIS,
            default => null,
        };
    }

    public static function rotulo(?string $porte): ?string
    {
        return self::ROTULOS[$porte] ?? null;
    }
}
