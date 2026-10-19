<?php

namespace App\Exports;

use App\Models\Segmento;
use App\Services\VisaoDiretor\PesquisaSupermercadista;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Planilha da tabela de um segmento (Visão Diretor → Maiores por Segmento).
 *
 * As outras abas saem de `UniversoDoSegmento::tudo()`, a mesma lista da tela. O
 * supermercadista (101) sai de `PesquisaSupermercadista`: cliente, lead e rede da ABRAS
 * sem cliente, com lojas na Receita e o faturamento anual. A tela do 101 não muda.
 *
 * `headings()` e `map()` são listas paralelas mantidas à mão; há teste conferindo o tamanho.
 */
class ClientesLeadsSegmentoExport implements FromArray, WithHeadings
{
    /** Mesmo mapa do front (`ROTULOS_STATUS_CONTA`). Mudou lá, muda aqui. */
    private const ROTULOS_STATUS = [
        'ativo' => 'Ativo',
        'inativando' => 'Perdendo',
        'inativo' => 'A trabalhar',
        'lead' => 'Lead',
    ];

    /**
     * Guarda só o recorte: as linhas são montadas em `array()`, quando o arquivo é gerado
     * (na fila, se passar do limite) — nunca dentro da requisição que só precisa contar.
     *
     * @param  array{status?: string, busca?: string, uf?: string}  $filtros
     */
    public function __construct(
        private readonly string $segmento,
        private readonly array $filtros,
    ) {}

    public function array(): array
    {
        $linhas = $this->segmento === Segmento::CODIGO_SUPERMERCADISTA
            ? app(PesquisaSupermercadista::class)->linhas($this->filtros)
            : app(UniversoDoSegmento::class)->tudo($this->segmento, $this->filtros);

        return array_map(fn (array $l) => $this->map($l), $linhas);
    }

    public function headings(): array
    {
        $colunas = ['Razão social', 'Nome fantasia', 'Tipo', 'CNPJ', 'Grupo', 'UF', 'Filiais'];

        if ($this->segmento === Segmento::CODIGO_SUPERMERCADISTA) {
            array_push($colunas, 'Lojas ativas na Receita', 'Faturamento anual (R$)', 'Ano do faturamento', 'Fonte');
        }

        return [...$colunas, 'Status', 'Vendedor', 'Segmento'];
    }

    /** @param  array<string, mixed>  $l */
    public function map(array $l): array
    {
        $nome = isset($l['posicao']) && $l['posicao']
            ? $l['posicao'].'. '.$l['nome']
            : $l['nome'];

        $linha = [
            $nome,
            $l['nomeFantasia'] ?? '',
            match ($l['tipo']) {
                'lead' => 'Lead',
                'rede' => 'Rede ABRAS',
                default => 'Cliente',
            },
            $l['documento'] ?? '',
            $l['grupo'] ?? '',
            $l['uf'] ?? '',
            $l['filiais'] ?? '',
        ];

        if ($this->segmento === Segmento::CODIGO_SUPERMERCADISTA) {
            array_push($linha, $l['lojasReceita'] ?? '', ...$this->faturamento($l));
        }

        return [
            ...$linha,
            self::ROTULOS_STATUS[$l['status']] ?? $l['status'],
            $l['vendedor'] ?? '',
            $l['segmentoNome'],
        ];
    }

    /**
     * Célula vazia quando ninguém declarou o número. Vazio não vira zero.
     *
     * @param  array<string, mixed>  $l
     * @return array{0: int|string, 1: int|string, 2: string}
     */
    private function faturamento(array $l): array
    {
        if (($l['faturamento'] ?? null) === null) {
            return ['', '', ''];
        }

        return [
            $l['faturamento'],
            $l['anoFaturamento'] ?? '',
            match ($l['fonteFaturamento'] ?? null) {
                'abras' => 'ABRAS',
                'web' => $this->dominio($l['urlFaturamento'] ?? null),
                default => '',
            },
        ];
    }

    private function dominio(?string $url): string
    {
        $host = $url ? (parse_url($url, PHP_URL_HOST) ?: '') : '';

        return preg_replace('/^www\./', '', $host) ?: '';
    }
}
