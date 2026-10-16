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
    /** @var array<string, array{rotulo: string, segmento: ?string}> */
    public const CATEGORIAS = [
        'drogarias' => ['rotulo' => 'Drogarias', 'segmento' => '109'],
        'estacionamentos' => ['rotulo' => 'Estacionamentos', 'segmento' => '113'],
        'postos' => ['rotulo' => 'Postos de gasolina', 'segmento' => '114'],
        'material_construcao' => ['rotulo' => 'Material de construção', 'segmento' => '120'],
        'alimentacao' => ['rotulo' => 'Alimentação', 'segmento' => '112'],
        'rede_lojas' => ['rotulo' => 'Rede de lojas', 'segmento' => '108'],
        'supermercados' => ['rotulo' => 'Supermercados / atacarejo', 'segmento' => '101'],
        'conveniencia' => ['rotulo' => 'Conveniência / autosserviço', 'segmento' => null],
        'beleza_saude' => ['rotulo' => 'Beleza / saúde / bem-estar', 'segmento' => null],
        'oticas' => ['rotulo' => 'Óticas', 'segmento' => null],
        'servicos_franquias' => ['rotulo' => 'Serviços / franquias', 'segmento' => null],
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
}
