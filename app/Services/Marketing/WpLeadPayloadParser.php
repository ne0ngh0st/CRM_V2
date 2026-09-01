<?php

namespace App\Services\Marketing;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Único lugar que interpreta o payload cru do WordPress/CSV.
 *
 * O envelope JSON continua sendo a fonte de verdade (nenhum campo do form é
 * descartado). Daqui sai só a projeção comercial — o que tem coluna em `leads`.
 *
 * ⚠️ Os aliases NÃO são chute: vieram de ler o formulário real em
 * https://www.autopel.com/fale-conosco (CF7 id 83), que manda `name`,
 * `empresa`, `segmento`, `cnpj`, `estado`, `cidade`, `endereco`, `email`,
 * `mc4wp-PHONE`, `assunto`, `itens[]` e `mensagem`. Antes disso o parser
 * perdia telefone, CNPJ, estado e segmento em silêncio — o lead chegava com
 * nome e e-mail e mais nada, e o vendedor não tinha como ligar.
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

    /** @var list<string> */
    private const ALIASES_CNPJ = ['cnpj', 'cnpj_cpf', 'documento', 'doc'];

    /** @var list<string> */
    private const ALIASES_ESTADO = ['estado', 'uf', 'state', 'seu_estado'];

    /** @var list<string> */
    private const ALIASES_CIDADE = ['cidade', 'city', 'municipio', 'sua_cidade'];

    /** @var list<string> */
    private const ALIASES_ENDERECO = ['endereco', 'address', 'logradouro', 'seu_endereco'];

    /** @var list<string> */
    private const ALIASES_SEGMENTO = ['segmento', 'segment', 'ramo', 'setor', 'area'];

    /** @var list<string> */
    private const ALIASES_ASSUNTO = ['assunto', 'subject', 'motivo', 'departamento'];

    /**
     * `leads.estado` é varchar(2) e todos os 17.173 leads existentes usam sigla.
     * O campo do site é texto livre ("Seu estado"), então vem "São Paulo" tanto
     * quanto "SP". Truncar seria gravar "Sã" — corrupção silenciosa.
     *
     * @var array<string, string>
     */
    private const UF_POR_NOME = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP', 'amazonas' => 'AM',
        'bahia' => 'BA', 'ceara' => 'CE', 'distrito_federal' => 'DF',
        'espirito_santo' => 'ES', 'goias' => 'GO', 'maranhao' => 'MA',
        'mato_grosso' => 'MT', 'mato_grosso_do_sul' => 'MS', 'minas_gerais' => 'MG',
        'para' => 'PA', 'paraiba' => 'PB', 'parana' => 'PR', 'pernambuco' => 'PE',
        'piaui' => 'PI', 'rio_de_janeiro' => 'RJ', 'rio_grande_do_norte' => 'RN',
        'rio_grande_do_sul' => 'RS', 'rondonia' => 'RO', 'roraima' => 'RR',
        'santa_catarina' => 'SC', 'sao_paulo' => 'SP', 'sergipe' => 'SE',
        'tocantins' => 'TO',
    ];

    /** @var list<string> */
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS',
        'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
        'SP', 'SE', 'TO',
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
     * @return array{nome: ?string, email: ?string, telefone: ?string, empresa: ?string,
     *               cnpj: ?string, estado: ?string, cidade: ?string, endereco: ?string,
     *               segmento: ?string, assunto: ?string}
     */
    public function extrairCampos(array $parsed): array
    {
        return [
            'nome' => $this->valorPorAliases($parsed, self::ALIASES_NOME),
            'email' => $this->valorPorAliases($parsed, self::ALIASES_EMAIL),
            'telefone' => $this->valorPorAliases($parsed, self::ALIASES_TELEFONE),
            'empresa' => $this->valorPorAliases($parsed, self::ALIASES_EMPRESA),
            'cnpj' => $this->valorPorAliases($parsed, self::ALIASES_CNPJ),
            'estado' => $this->normalizarUf($this->valorPorAliases($parsed, self::ALIASES_ESTADO)),
            'cidade' => $this->valorPorAliases($parsed, self::ALIASES_CIDADE),
            'endereco' => $this->valorPorAliases($parsed, self::ALIASES_ENDERECO),
            'segmento' => $this->valorPorAliases($parsed, self::ALIASES_SEGMENTO),
            'assunto' => $this->valorPorAliases($parsed, self::ALIASES_ASSUNTO),
        ];
    }

    /**
     * "São Paulo" → SP; "sp" → SP; "Seu estado" ou lixo → null.
     * Nunca trunca: sigla errada é pior que campo vazio, porque o filtro de
     * estado da /leads passa a mentir.
     */
    public function normalizarUf(?string $valor): ?string
    {
        $valor = $valor !== null ? trim($valor) : '';
        if ($valor === '') {
            return null;
        }

        $sigla = mb_strtoupper($valor);
        if (in_array($sigla, self::UFS, true)) {
            return $sigla;
        }

        return self::UF_POR_NOME[$this->normalizarChave($valor)] ?? null;
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
            if (! is_string($chave)) {
                continue;
            }

            // Checkbox do CF7 chega como lista (`itens[]`); junta em texto.
            if (is_array($valor)) {
                $valor = implode(', ', array_filter(array_map(
                    static fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                    $valor,
                )));
            }

            if (! is_scalar($valor) && $valor !== null) {
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

    /**
     * ⚠️ O `mc4wp_` do fim é o que faz o telefone chegar. O Mailchimp for
     * WordPress prefixa os campos que ele controla, e no form da Autopel o
     * telefone se chama `mc4wp-PHONE` — sem tirar o prefixo, nenhum alias bate
     * e o lead nasce sem o único dado que o vendedor usa para agir.
     */
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
        $chave = str_replace([' ', '-', '[', ']'], ['_', '_', '', ''], $chave);

        return preg_replace('/^mc4wp_/', '', $chave) ?? $chave;
    }
}
