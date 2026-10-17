<?php

namespace App\Services\VisaoDiretor\Redes;

/**
 * As 11 categorias da planilha "MAIORES POR SEGMENTO 2026" (página Maiores redes), na
 * ordem da planilha. É a ÚNICA lista: o script que gera o JSON
 * (`scripts/redes_mercado_xlsx_para_json.py`) escreve estas chaves, e as abas da página
 * saem daqui.
 *
 * ⚠️ Não são os segmentos do TOTVS. A categoria é do MERCADO e quatro delas nem existem
 * no TOTVS. `segmento` é o código TOTVS equivalente, quando há: ele só serve para a trava
 * de "filial avulsa no segmento da rede" do `VinculoPorCliente`. Sem equivalente (null), a
 * filial vale em qualquer segmento e só as travas de nome seguram o casamento.
 */
final class CategoriasDeRede
{
    /**
     * `cnaes`: prefixos do CNAE principal de uma LOJA da categoria, para o
     * `CnpjDasRedes` contar só as lojas do ramo certo ("Cacique" posto × "Cacique" pneus).
     * Vazio = qualquer CNAE.
     *
     * @var array<string, array{rotulo: string, segmento: ?string, cnaes: list<string>}>
     */
    public const CATEGORIAS = [
        'drogarias' => ['rotulo' => 'Drogarias', 'segmento' => '109', 'cnaes' => ['4771']],
        'estacionamentos' => ['rotulo' => 'Estacionamentos', 'segmento' => '113', 'cnaes' => ['5223']],
        'postos' => ['rotulo' => 'Postos de gasolina', 'segmento' => '114', 'cnaes' => ['4731', '4732']],
        'material_construcao' => ['rotulo' => 'Material de construção', 'segmento' => '120', 'cnaes' => ['4741', '4742', '4743', '4744', '4672', '4673', '4674', '4679', '4754']],
        'alimentacao' => ['rotulo' => 'Alimentação', 'segmento' => '112', 'cnaes' => ['561', '1091', '1092', '1093', '4721', '4722', '4724', '4729']],
        'rede_lojas' => ['rotulo' => 'Rede de lojas', 'segmento' => '108', 'cnaes' => ['47']],
        'supermercados' => ['rotulo' => 'Supermercados / atacarejo', 'segmento' => '101', 'cnaes' => ['4711', '4712', '4639', '4691', '4729']],
        'conveniencia' => ['rotulo' => 'Conveniência / autosserviço', 'segmento' => null, 'cnaes' => ['4729', '4711', '4712', '4723', '4731']],
        'beleza_saude' => ['rotulo' => 'Beleza / saúde / bem-estar', 'segmento' => null, 'cnaes' => ['9602', '4772', '9313', '86', '9609', '4771']],
        'oticas' => ['rotulo' => 'Óticas', 'segmento' => null, 'cnaes' => ['4774']],
        'servicos_franquias' => ['rotulo' => 'Serviços / franquias', 'segmento' => null, 'cnaes' => []],
    ];

    public static function rotulo(string $categoria): string
    {
        return self::CATEGORIAS[$categoria]['rotulo'] ?? $categoria;
    }

    public static function segmento(string $categoria): ?string
    {
        if (! isset(self::CATEGORIAS[$categoria])) {
            throw new \InvalidArgumentException("Categoria de rede desconhecida: {$categoria}");
        }

        return self::CATEGORIAS[$categoria]['segmento'];
    }

    /** O CNAE (7 dígitos) é de uma loja da categoria? Categoria sem lista aceita qualquer um. */
    public static function cnaeDaCategoria(string $categoria, ?string $cnae): bool
    {
        $prefixos = self::CATEGORIAS[$categoria]['cnaes'] ?? [];

        if ($prefixos === []) {
            return true;
        }

        foreach ($prefixos as $p) {
            if (str_starts_with((string) $cnae, $p)) {
                return true;
            }
        }

        return false;
    }
}
