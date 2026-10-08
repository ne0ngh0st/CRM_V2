<?php

namespace App\Services\VisaoDiretor;

use App\Models\Segmento;
use Illuminate\Support\Collection;

/**
 * As abas da página Maiores por Segmento: as seis da planilha "MAIORES POR SEGMENTO -
 * CRM.xlsx" e, desde 2026-10-08, SUPERMERCADISTA, que não existe na planilha — as contas
 * dela vêm do ranking da ABRAS (`diretor:importar-ranking-abras`).
 *
 * Mora aqui (e não no comando de import nem na página) porque a tela, a carga inicial e
 * qualquer relatório futuro precisam da MESMA lista, na MESMA ordem — senão a aba
 * "Postos" da tela deixa de ser a aba "POSTOS COMBUSTIVEL" da planilha sem ninguém
 * perceber. Regra de ouro nº 8.
 *
 * ⚠️ ABAS PELO NOME, nunca por posição. A chave é o título da aba normalizado
 * (`mb_strtoupper` + ASCII, espaços colapsados), o valor é o código TOTVS.
 */
final class AbasDaPlanilha
{
    public const ABAS = [
        // Primeira aba (decisão do Tony): é o nosso segmento dominante.
        'SUPERMERCADISTA' => '101',
        'DROGARIAS' => '109',
        'REDE LOJAS' => '108',
        'ALIMENTACAO' => '112',
        'POSTOS COMBUSTIVEL' => '114',
        'MATERIAL CONSTRUCAO' => '120',
        'ESTACIONAMENTOS' => '113',
    ];

    /**
     * Abas que a planilha da diretoria não tem: a carga dela (`diretor:importar-maiores-
     * segmento`) as pula em silêncio, em vez de avisar "aba não encontrada".
     */
    public const FORA_DA_PLANILHA = ['SUPERMERCADISTA'];

    /** @return list<string> códigos TOTVS, na ordem das abas da tela */
    public static function codigos(): array
    {
        return array_values(self::ABAS);
    }

    /**
     * Os segmentos do CRM que correspondem às abas, na ordem da planilha.
     *
     * Segmento que ainda não existe na tabela (banco sem o seeder) é pulado — a aba
     * simplesmente não aparece, em vez de a página quebrar.
     *
     * @return Collection<int, Segmento>
     */
    public static function segmentos(): Collection
    {
        $encontrados = Segmento::query()
            ->whereIn('codigo', self::codigos())
            ->get()
            ->keyBy('codigo');

        return collect(self::codigos())
            ->map(fn (string $codigo) => $encontrados->get($codigo))
            ->filter()
            ->values();
    }
}
