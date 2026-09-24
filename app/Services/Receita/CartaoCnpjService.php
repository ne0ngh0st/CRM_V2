<?php

namespace App\Services\Receita;

use App\Models\Cliente;
use App\Models\CnpjConsulta;
use App\Models\Lead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cartão CNPJ: consulta, normaliza, grava e compara com o cadastro do TOTVS.
 *
 * É o ÚNICO lugar que conhece o formato de cada provedor. Para fora sai sempre o mesmo
 * array (`normalizar*()`), gravado assim em `cnpj_consultas.dados` — trocar ou
 * acrescentar fonte não mexe na tela nem no que já está no banco.
 *
 * ⚠️ Quadro de sócios NÃO é lido nem guardado, de propósito (LGPD: nome de pessoa
 * física). O provedor manda; o normalizador descarta. Se um dia entrar, é decisão
 * consciente, não um campo a mais no mapa.
 */
class CartaoCnpjService
{
    public const NAO_ENCONTRADO = 'nao_encontrado';

    public const INDISPONIVEL = 'indisponivel';

    /** Só CNPJ de 14 dígitos; CPF (11) e lixo devolvem null. */
    public static function normalizarCnpj(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) === 14 ? $digitos : null;
    }

    /**
     * @return array{status: 'ok'|'nao_encontrado'|'indisponivel', consulta: ?CnpjConsulta, desatualizado: bool}
     *
     * `desatualizado` = as fontes falharam, mas havia um cartão antigo gravado: melhor
     * mostrar o de três meses atrás (com a data na tela) do que nada.
     */
    public function consultar(string $cnpj, bool $forcar = false): array
    {
        $existente = CnpjConsulta::query()->where('cnpj', $cnpj)->first();

        $fresco = $existente
            && $existente->consultado_em->gt(now()->subDays(config('receita.validade_dias')));

        if ($fresco && ! $forcar) {
            return ['status' => 'ok', 'consulta' => $existente, 'desatualizado' => false];
        }

        $naoEncontradoEmTodas = true;

        foreach (config('receita.fontes') as $fonte) {
            $resultado = $this->buscarNaFonte($fonte, $cnpj);

            if (is_array($resultado)) {
                $consulta = CnpjConsulta::query()->updateOrCreate(['cnpj' => $cnpj], [
                    'situacao' => $resultado['situacao'],
                    'dados' => $resultado,
                    'fonte' => $fonte,
                    'consultado_em' => now(),
                ]);

                return ['status' => 'ok', 'consulta' => $consulta, 'desatualizado' => false];
            }

            if ($resultado !== self::NAO_ENCONTRADO) {
                $naoEncontradoEmTodas = false;
            }
        }

        if ($existente) {
            return ['status' => 'ok', 'consulta' => $existente, 'desatualizado' => true];
        }

        return [
            'status' => $naoEncontradoEmTodas ? self::NAO_ENCONTRADO : self::INDISPONIVEL,
            'consulta' => null,
            'desatualizado' => false,
        ];
    }

    /** @return array<string, mixed>|string  cartão normalizado, ou o motivo da falha */
    private function buscarNaFonte(string $fonte, string $cnpj): array|string
    {
        $url = match ($fonte) {
            'brasilapi' => "https://brasilapi.com.br/api/cnpj/v1/{$cnpj}",
            'minhareceita' => "https://minhareceita.org/{$cnpj}",
            'cnpja' => "https://open.cnpja.com/office/{$cnpj}",
            default => null,
        };

        if ($url === null) {
            return self::INDISPONIVEL;
        }

        try {
            $resposta = Http::timeout(config('receita.timeout_segundos'))
                ->connectTimeout(2)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('cartao-cnpj: fonte sem resposta', ['fonte' => $fonte, 'erro' => $e->getMessage()]);

            return self::INDISPONIVEL;
        }

        if ($resposta->status() === 404) {
            return self::NAO_ENCONTRADO;
        }

        if (! $resposta->successful() || ! is_array($resposta->json())) {
            Log::warning('cartao-cnpj: fonte recusou', ['fonte' => $fonte, 'http' => $resposta->status()]);

            return self::INDISPONIVEL;
        }

        $cartao = $fonte === 'cnpja'
            ? $this->normalizarCnpja($resposta->json())
            : $this->normalizarBrasilApi($resposta->json());

        /*
         * Sem razão social o cartão não serve para nada — e é o sintoma de o provedor
         * ter mudado o formato. Vira falha (tenta a próxima fonte) e deixa rastro no
         * log: dado que some não acende luz vermelha sozinho.
         */
        if (blank($cartao['razaoSocial']) || blank($cartao['situacao'])) {
            Log::warning('cartao-cnpj: formato inesperado', ['fonte' => $fonte, 'chaves' => array_keys($resposta->json())]);

            return self::INDISPONIVEL;
        }

        return $cartao;
    }

    /** BrasilAPI e minhareceita: mesmo formato (a BrasilAPI é servida pela minhareceita). */
    private function normalizarBrasilApi(array $d): array
    {
        $telefones = array_values(array_filter([
            $this->formatarTelefone($d['ddd_telefone_1'] ?? null),
            $this->formatarTelefone($d['ddd_telefone_2'] ?? null),
        ]));

        $logradouro = trim(($d['descricao_tipo_de_logradouro'] ?? '').' '.($d['logradouro'] ?? ''));

        return [
            'cnpj' => $d['cnpj'] ?? null,
            'razaoSocial' => $d['razao_social'] ?? null,
            'nomeFantasia' => ($d['nome_fantasia'] ?? null) ?: null,
            'situacao' => $this->situacao($d['descricao_situacao_cadastral'] ?? null),
            'dataSituacao' => $d['data_situacao_cadastral'] ?? null,
            // "SEM MOTIVO" é o valor da Receita para situação sem ocorrência — não é motivo.
            'motivoSituacao' => in_array($d['descricao_motivo_situacao_cadastral'] ?? null, [null, '', 'SEM MOTIVO'], true)
                ? null : $d['descricao_motivo_situacao_cadastral'],
            'dataAbertura' => $d['data_inicio_atividade'] ?? null,
            'matriz' => isset($d['identificador_matriz_filial']) ? (int) $d['identificador_matriz_filial'] === 1 : null,
            'naturezaJuridica' => $d['natureza_juridica'] ?? null,
            'porte' => $d['porte'] ?? null,
            'simples' => $d['opcao_pelo_simples'] ?? null,
            'mei' => $d['opcao_pelo_mei'] ?? null,
            'capitalSocial' => isset($d['capital_social']) ? (float) $d['capital_social'] : null,
            'cnaePrincipal' => isset($d['cnae_fiscal'])
                ? ['codigo' => $this->formatarCnae($d['cnae_fiscal']), 'descricao' => $d['cnae_fiscal_descricao'] ?? null]
                : null,
            'cnaesSecundarios' => array_values(array_map(
                fn ($c) => ['codigo' => $this->formatarCnae($c['codigo'] ?? null), 'descricao' => $c['descricao'] ?? null],
                // A Receita manda um item "código 0" quando não há secundário.
                array_filter($d['cnaes_secundarios'] ?? [], fn ($c) => ! empty($c['codigo'])),
            )),
            'endereco' => [
                'logradouro' => $logradouro ?: null,
                'numero' => $d['numero'] ?? null,
                'complemento' => ($d['complemento'] ?? null) ?: null,
                'bairro' => $d['bairro'] ?? null,
                'municipio' => $d['municipio'] ?? null,
                'uf' => $d['uf'] ?? null,
                'cep' => $d['cep'] ?? null,
            ],
            'telefones' => $telefones,
            'email' => ($d['email'] ?? null) ?: null,
        ];
    }

    private function normalizarCnpja(array $d): array
    {
        $empresa = $d['company'] ?? [];
        $end = $d['address'] ?? [];

        return [
            'cnpj' => $d['taxId'] ?? null,
            'razaoSocial' => $empresa['name'] ?? null,
            'nomeFantasia' => $d['alias'] ?? null,
            'situacao' => $this->situacao($d['status']['text'] ?? null),
            'dataSituacao' => $d['statusDate'] ?? null,
            'motivoSituacao' => $d['reason']['text'] ?? null,
            'dataAbertura' => $d['founded'] ?? null,
            'matriz' => $d['head'] ?? null,
            'naturezaJuridica' => $empresa['nature']['text'] ?? null,
            'porte' => isset($empresa['size']['text']) ? mb_strtoupper($empresa['size']['text']) : null,
            'simples' => $empresa['simples']['optant'] ?? null,
            'mei' => $empresa['simei']['optant'] ?? null,
            'capitalSocial' => isset($empresa['equity']) ? (float) $empresa['equity'] : null,
            'cnaePrincipal' => isset($d['mainActivity']['id'])
                ? ['codigo' => $this->formatarCnae($d['mainActivity']['id']), 'descricao' => $d['mainActivity']['text'] ?? null]
                : null,
            'cnaesSecundarios' => array_values(array_map(
                fn ($c) => ['codigo' => $this->formatarCnae($c['id'] ?? null), 'descricao' => $c['text'] ?? null],
                $d['sideActivities'] ?? [],
            )),
            'endereco' => [
                'logradouro' => $end['street'] ?? null,
                'numero' => $end['number'] ?? null,
                'complemento' => $end['details'] ?? null,
                'bairro' => $end['district'] ?? null,
                'municipio' => isset($end['city']) ? mb_strtoupper($end['city']) : null,
                'uf' => $end['state'] ?? null,
                'cep' => $end['zip'] ?? null,
            ],
            'telefones' => array_values(array_filter(array_map(
                fn ($t) => $this->formatarTelefone(($t['area'] ?? '').($t['number'] ?? '')),
                $d['phones'] ?? [],
            ))),
            'email' => $d['emails'][0]['address'] ?? null,
        ];
    }

    /**
     * A resposta HTTP do botão "Verificar cartão CNPJ", igual para Carteira e Leads —
     * cada controller só autoriza e diz qual é o nosso cadastro. JSON porque o modal
     * abre na hora e busca por baixo: a consulta externa nunca segura a tela.
     */
    public function resposta(?string $cnpjBruto, bool $atualizar, array $cadastro): JsonResponse
    {
        $cnpj = self::normalizarCnpj($cnpjBruto);

        if ($cnpj === null) {
            return response()->json(['erro' => 'Sem CNPJ de 14 dígitos cadastrado (pode ser CPF).'], 422);
        }

        $resultado = $this->consultar($cnpj, $atualizar);

        return match ($resultado['status']) {
            'ok' => response()->json($this->paraTela($resultado['consulta'], $resultado['desatualizado'], $cadastro)),
            self::NAO_ENCONTRADO => response()->json(['erro' => 'A Receita não encontrou este CNPJ.'], 404),
            default => response()->json(['erro' => 'A consulta à Receita está indisponível agora. Tente de novo em alguns minutos.'], 503),
        };
    }

    /** O cadastro do TOTVS, no formato que `divergencias()` compara. */
    public static function cadastroDoCliente(Cliente $cliente): array
    {
        return [
            'origem' => 'TOTVS',
            'razaoSocial' => $cliente->razao_social,
            'cep' => $cliente->cep,
            'municipio' => $cliente->municipio,
            'uf' => $cliente->estado,
        ];
    }

    /** O que o vendedor/formulário do site digitou no lead. Lead não tem CEP. */
    public static function cadastroDoLead(Lead $lead): array
    {
        return [
            'origem' => 'Lead',
            'razaoSocial' => $lead->razao_social,
            'cep' => null,
            'municipio' => $lead->cidade,
            'uf' => $lead->estado,
        ];
    }

    /**
     * O que a TELA recebe: o cartão com datas e CEP formatados, mais o carimbo de
     * quando/onde foi consultado e as divergências contra o nosso cadastro (o do
     * TOTVS, para cliente; o digitado, para lead — ver `cadastroDo*()`).
     */
    public function paraTela(CnpjConsulta $consulta, bool $desatualizado, ?array $cadastro = null): array
    {
        $d = $consulta->dados;

        $data = fn (?string $iso) => $iso ? Carbon::parse($iso)->format('d/m/Y') : null;
        $cep = fn (?string $v) => ($dig = preg_replace('/\D/', '', (string) $v)) && strlen($dig) === 8
            ? substr($dig, 0, 5).'-'.substr($dig, 5) : ($v ?: null);

        $d['cnpjFormatado'] = $this->formatarCnpj($consulta->cnpj);
        $d['dataSituacao'] = $data($d['dataSituacao'] ?? null);
        $d['dataAbertura'] = $data($d['dataAbertura'] ?? null);
        $d['endereco']['cep'] = $cep($d['endereco']['cep'] ?? null);

        return [
            'cartao' => $d,
            'consultadoEm' => $consulta->consultado_em->format('d/m/Y H:i'),
            'diasDesdeConsulta' => (int) $consulta->consultado_em->copy()->startOfDay()->diffInDays(now()->startOfDay()),
            'fonte' => $consulta->fonte,
            'desatualizado' => $desatualizado,
            // "TOTVS" ou "Lead": a tela diz de ONDE veio o valor que diverge.
            'origemCadastro' => $cadastro['origem'] ?? null,
            'divergencias' => $cadastro ? $this->divergencias($d, $cadastro) : [],
        ];
    }

    /**
     * Onde a Receita discorda do nosso cadastro. Só campos que comparam bem:
     * logradouro e número vêm num texto livre só no TOTVS e dariam falso alarme em
     * quase toda linha — aviso que dispara sempre é aviso que ninguém lê.
     *
     * @param  array{razaoSocial: ?string, cep: ?string, municipio: ?string, uf: ?string}  $cadastro
     * @return array<int, array{campo: string, cadastro: string, receita: string}>
     */
    public function divergencias(array $cartao, array $cadastro): array
    {
        $end = $cartao['endereco'] ?? [];

        $pares = [
            'Razão social' => [$cadastro['razaoSocial'] ?? null, $cartao['razaoSocial'] ?? null, 'prefixo'],
            'CEP' => [$cadastro['cep'] ?? null, $end['cep'] ?? null, 'igual'],
            'Município' => [$cadastro['municipio'] ?? null, $end['municipio'] ?? null, 'igual'],
            'UF' => [$cadastro['uf'] ?? null, $end['uf'] ?? null, 'igual'],
        ];

        $saida = [];

        foreach ($pares as $campo => [$nosso, $receita, $modo]) {
            // Sem valor de um dos lados não há o que comparar — lacuna não é divergência.
            if (blank($nosso) || blank($receita)) {
                continue;
            }

            $bate = $modo === 'prefixo'
                ? $this->mesmoNome($nosso, $receita)
                : $this->chaveComparacao($nosso) === $this->chaveComparacao($receita);

            if (! $bate) {
                $saida[] = ['campo' => $campo, 'cadastro' => (string) $nosso, 'receita' => (string) $receita];
            }
        }

        return $saida;
    }

    /**
     * Razão social: o TOTVS TRUNCA nome longo e ABREVIA palavra para caber no campo.
     * Casos reais: "IMIFARMA PROD FARMA E COSMETICOS SA" é a mesma empresa que
     * "IMIFARMA PRODUTOS FARMACEUTICOS E COSMETICOS SA". Por isso cada palavra só
     * precisa COMEÇAR igual à do outro lado, na mesma posição. A primeira palavra
     * diferente de verdade ("24715-MANOEL" × "FUNDACAO") continua acusando.
     *
     * O texto corrido sem espaços também vale, para "SUPERMERCADOS" × "SUPER MERCADOS".
     */
    private function mesmoNome(string $nosso, string $receita): bool
    {
        $a = $this->palavrasComparacao($nosso);
        $b = $this->palavrasComparacao($receita);

        $juntoA = implode('', $a);
        $juntoB = implode('', $b);

        if (str_starts_with($juntoA, $juntoB) || str_starts_with($juntoB, $juntoA)) {
            return true;
        }

        for ($i = 0, $n = min(count($a), count($b)); $i < $n; $i++) {
            if (! str_starts_with($a[$i], $b[$i]) && ! str_starts_with($b[$i], $a[$i])) {
                return false;
            }
        }

        return true;
    }

    private function chaveComparacao(string $valor): string
    {
        return implode('', $this->palavrasComparacao($valor));
    }

    /**
     * Sem acento, maiúsculo, só letras/dígitos e SEM o sufixo de tipo societário no fim:
     * "S/A" = "SA" = "AS", "LTDA." = "LTDA" = nada. Caso real que motivou: o TOTVS tem
     * "IMIFARMA ... COSMETICOS AS" e a Receita "... COSMETICOS SA" — mesma empresa, e
     * um aviso de divergência ali só ensinaria o usuário a ignorar o aviso.
     *
     * @return array<int, string>
     */
    private function palavrasComparacao(string $valor): array
    {
        $palavras = preg_split('/[^A-Z0-9]+/', mb_strtoupper(Str::ascii($valor)), -1, PREG_SPLIT_NO_EMPTY);

        while (count($palavras) > 1) {
            $fim = end($palavras);
            $doisUltimos = implode('', array_slice($palavras, -2));

            if (in_array($doisUltimos, ['SA'], true)) {
                array_splice($palavras, -2);
            } elseif (in_array($fim, ['LTDA', 'SA', 'AS', 'ME', 'EPP', 'EIRELI'], true)) {
                array_pop($palavras);
            } else {
                break;
            }
        }

        return $palavras;
    }

    private function situacao(?string $texto): ?string
    {
        return $texto ? mb_strtoupper(Str::ascii(trim($texto))) : null;
    }

    private function formatarCnae(int|string|null $codigo): ?string
    {
        $dig = str_pad(preg_replace('/\D/', '', (string) $codigo), 7, '0', STR_PAD_LEFT);

        return $codigo ? substr($dig, 0, 4).'-'.substr($dig, 4, 1).'/'.substr($dig, 5, 2) : null;
    }

    private function formatarTelefone(?string $valor): ?string
    {
        $dig = preg_replace('/\D/', '', (string) $valor);

        return match (strlen($dig)) {
            10 => sprintf('(%s) %s-%s', substr($dig, 0, 2), substr($dig, 2, 4), substr($dig, 6)),
            11 => sprintf('(%s) %s-%s', substr($dig, 0, 2), substr($dig, 2, 5), substr($dig, 7)),
            default => null,
        };
    }

    private function formatarCnpj(string $d): string
    {
        return sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12, 2));
    }
}
