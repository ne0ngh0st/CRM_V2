<?php

namespace App\Services\Marketing;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Único lugar que interpreta o payload cru do WordPress/CSV.
 *
 * O envelope JSON continua sendo a fonte de verdade (nenhum campo do form é
 * descartado). Daqui sai só a projeção comercial: nome, e-mail, telefone,
 * empresa — e, no CSV, a data original do envio se o arquivo tiver uma coluna
 * reconhecível. Sem isso o volume histórico ficaria todo no dia do import.
 */
class WpLeadPayloadParser
{
    private const TZ = 'America/Sao_Paulo';

    /** @var list<string> */
    private const ALIASES_NOME = [
        'nome', 'name', 'your-name', 'your_name', 'full_name', 'fullname',
        'nome_completo', 'nomecompleto', 'contato', 'contact-name', 'contact_name',
        'first-name', 'first_name', 'seu_nome', 'seu-nome',
    ];

    /** @var list<string> */
    private const ALIASES_EMAIL = [
        'email', 'e-mail', 'e_mail', 'your-email', 'your_email', 'mail',
        'seu_email', 'seu-email',
    ];

    /** @var list<string> */
    private const ALIASES_TELEFONE = [
        'telefone', 'phone', 'tel', 'celular', 'whatsapp', 'your-phone',
        'your_phone', 'telefone_celular', 'fone', 'seu_telefone', 'seu-telefone',
    ];

    /** @var list<string> */
    private const ALIASES_EMPRESA = [
        'empresa', 'company', 'razao_social', 'razao-social', 'razaosocial',
        'nome_empresa', 'nome-empresa', 'organization', 'organizacao',
        'sua_empresa', 'sua-empresa',
    ];

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    public function parsearCorpo(string $raw, array $post): array
    {
        $parsed = [];

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        if ($parsed === [] && $post !== []) {
            $parsed = $post;
        }

        return array_merge($parsed, $this->achatarFields($parsed));
    }

    /**
     * Contact Form 7 (e similares) mandam `{ "fields": { "nome": "..." } }`.
     * As chaves sobem para o mesmo nível de `parsed` sem apagar o resto.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function achatarFields(array $data): array
    {
        if (! isset($data['fields']) || ! is_array($data['fields'])) {
            return [];
        }

        $out = [];
        foreach ($data['fields'] as $chave => $valor) {
            if (is_string($chave) && (is_scalar($valor) || $valor === null)) {
                $out[$chave] = $valor;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{nome: ?string, email: ?string, telefone: ?string, empresa: ?string}
     */
    public function extrairCampos(array $parsed): array
    {
        return [
            'nome' => $this->valorPorAliases($parsed, self::ALIASES_NOME),
            'email' => $this->valorPorAliases($parsed, self::ALIASES_EMAIL),
            'telefone' => $this->valorPorAliases($parsed, self::ALIASES_TELEFONE),
            'empresa' => $this->valorPorAliases($parsed, self::ALIASES_EMPRESA),
        ];
    }

    /**
     * Data original do envio no CSV, se houver coluna reconhecível.
     * Sem match, o chamador usa o instante do import.
     *
     * @param  array<string, mixed>  $colunas
     */
    public function dataDoCsv(array $colunas): ?Carbon
    {
        $candidatas = [];
        foreach ($colunas as $cabecalho => $valor) {
            if (! is_string($cabecalho) || ! is_scalar($valor) || trim((string) $valor) === '') {
                continue;
            }
            $chave = $this->normalizarChave($cabecalho);
            if (! preg_match('/^(data|date|submitted|envio|enviado|created|timestamp|datetime)/', $chave)) {
                continue;
            }
            $candidatas[] = [
                'preferida' => (int) preg_match('/(submit|envio|enviado)/', $chave),
                'valor' => trim((string) $valor),
            ];
        }

        usort($candidatas, fn (array $a, array $b) => $b['preferida'] <=> $a['preferida']);

        foreach ($candidatas as $candidata) {
            try {
                $data = Carbon::parse($candidata['valor'], self::TZ);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($data->year < 2000 || $data->year > 2100) {
                continue;
            }

            return $data;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $aliases
     */
    private function valorPorAliases(array $parsed, array $aliases): ?string
    {
        $normalizado = [];
        foreach ($parsed as $chave => $valor) {
            if (! is_string($chave) || ! (is_scalar($valor) || $valor === null)) {
                continue;
            }
            $texto = trim((string) $valor);
            if ($texto === '') {
                continue;
            }
            $normalizado[$this->normalizarChave($chave)] = $texto;
        }

        foreach ($aliases as $alias) {
            $chave = $this->normalizarChave($alias);
            if (isset($normalizado[$chave])) {
                return $normalizado[$chave];
            }
        }

        return null;
    }

    private function normalizarChave(string $chave): string
    {
        $chave = mb_strtolower(trim($chave));
        $chave = strtr($chave, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return str_replace([' ', '-'], '_', $chave);
    }
}
