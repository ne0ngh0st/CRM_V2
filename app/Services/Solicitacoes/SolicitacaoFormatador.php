<?php

namespace App\Services\Solicitacoes;

/**
 * Formatação/rótulos comuns aos PDFs (e e-mails) de solicitação — bobina e etiqueta
 * hoje, reusa aqui em vez de duplicar (Regra de ouro nº 8). Cópia fiel das funções
 * `formatarNumeroPdf`/`formatarDataPdf`/`mapearSN`/`definirStatus` do legado
 * (`includes/pdf/solicitacao_pdf_layout.php` + `solicitacao_bobina_pdf.php`).
 */
class SolicitacaoFormatador
{
    /** [rótulo, cor do selo] — mesmas cores do `definirStatus()` do legado. */
    private const STATUS = [
        'pendente' => ['Pendente', '#D97706'],
        'enviado' => ['Enviado', '#16803D'],
        'enviada' => ['Enviada', '#16803D'],
        'aprovado' => ['Aprovado', '#16803D'],
        'aprovada' => ['Aprovada', '#16803D'],
        'reprovado' => ['Reprovado', '#B91C1C'],
        'reprovada' => ['Reprovada', '#B91C1C'],
        'cancelado' => ['Cancelado', '#64748B'],
        'cancelada' => ['Cancelada', '#64748B'],
    ];

    /** @return array{0: string, 1: string} [rótulo, cor] */
    public static function statusRotuloCor(?string $status): array
    {
        return self::STATUS[mb_strtolower(trim((string) $status))]
            ?? [ucfirst((string) $status), '#64748B'];
    }

    public static function logoPath(): ?string
    {
        $logoColorido = public_path('images/autopel-logo.png');
        $logoBranco = public_path('images/autopel-logo-white.png');

        return file_exists($logoColorido) ? $logoColorido : (file_exists($logoBranco) ? $logoBranco : null);
    }

    /** @param array<string, string> $mapa */
    public static function mapear(array $mapa, ?string $valor, bool $maiusculoSeDesconhecido = false): string
    {
        $chave = mb_strtolower(trim((string) $valor));

        if ($chave === '') {
            return '-';
        }

        return $mapa[$chave] ?? ($maiusculoSeDesconhecido ? mb_strtoupper($chave) : '-');
    }

    public static function simNao(?string $valor): string
    {
        return match (mb_strtolower(trim((string) $valor))) {
            'sim' => 'Sim',
            'nao', 'não' => 'Não',
            default => '-',
        };
    }

    /** Inteiro fica sem casas; decimal perde os zeros à direita — igual ao legado. */
    public static function numero(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        if (! is_numeric($valor)) {
            return (string) $valor;
        }

        $numero = (float) $valor;

        if ($numero === (float) (int) $numero) {
            return (string) (int) $numero;
        }

        return rtrim(rtrim(number_format($numero, 2, ',', ''), '0'), ',');
    }

    public static function data(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        return $valor instanceof \DateTimeInterface
            ? $valor->format('d/m/Y H:i')
            : (($ts = strtotime((string) $valor)) ? date('d/m/Y H:i', $ts) : '-');
    }
}
