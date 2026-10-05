<?php

namespace App\Services\Receita;

use Illuminate\Support\Facades\DB;

/**
 * Situação cadastral de um CNPJ na Receita — o único lugar que responde "este CNPJ
 * está ativo?" (Regra de ouro nº 8).
 *
 * Regra de negócio (Tony, 2026-10-01): lead da base de prospecção (`origem = sistema`)
 * com CNPJ que NÃO esteja ATIVA na Receita não entra no CRM. "Não ativa" é tudo:
 * SUSPENSA, INAPTA, BAIXADA, NULA e também INEXISTENTE (CNPJ que não está na base —
 * quase sempre dígito verificador errado).
 *
 * ⚠️ Situação DESCONHECIDA (CNPJ nunca verificado) não é "não ativa": quem decide o que
 * fazer com ela é o chamador. O import de leads segura o lead NOVO até a situação ser
 * conhecida, e não mexe em lead que já está no CRM.
 */
class SituacaoCadastral
{
    public const ATIVA = 'ATIVA';

    public const INEXISTENTE = 'INEXISTENTE';

    public const FONTE_BASE = 'receita_base';

    /*
     * Idade da carga mensal (em dias) a partir da qual a base é considerada velha. A
     * Receita publica uma vez por mês e a carga roda todo domingo, então até ~45 dias é
     * rotina. ⚠️ O alarme do CloudWatch (`infra/monitoramento/criar-alarmes-aplicacao.sh`,
     * `crm-v2-receita-desatualizada`) usa o mesmo 60 — mudar um, mudar o outro.
     */
    public const DIAS_ATENCAO = 45;

    public const DIAS_ALARME = 60;

    /** Código da coluna `SITUAÇÃO CADASTRAL` dos arquivos de Estabelecimentos. */
    public const CODIGOS = [
        1 => 'NULA',
        2 => 'ATIVA',
        3 => 'SUSPENSA',
        4 => 'INAPTA',
        8 => 'BAIXADA',
    ];

    public static function deCodigo(string $codigo): string
    {
        return self::CODIGOS[(int) $codigo] ?? 'CODIGO_'.trim($codigo);
    }

    public static function permiteLead(?string $situacao): bool
    {
        return $situacao === self::ATIVA;
    }

    /**
     * "Irregular" = situação CONHECIDA e diferente de ATIVA. Desconhecida (nunca
     * verificada) não é irregular — a Carteira não acusa o que não sabe.
     */
    public static function irregular(?string $situacao): bool
    {
        return $situacao !== null && $situacao !== self::ATIVA;
    }

    /**
     * A mesma regra de `irregular()` em SQL, para agregações e filtros. Os dois têm que
     * mudar juntos — é por isso que moram lado a lado.
     */
    public static function sqlIrregular(string $coluna): string
    {
        return "({$coluna} IS NOT NULL AND {$coluna} <> '".self::ATIVA."')";
    }

    /**
     * Idade da carga mensal da base aberta: o mês da base, quando foi carregada e há
     * quantos dias. `dias` é null quando nunca houve carga.
     *
     * É a definição única de "a base da Receita está velha?" — a tela `/atualizacoes` e a
     * métrica do CloudWatch (`ReceitaBaseIdadeDias`) leem daqui.
     *
     * @return array{referencia: ?string, carregadaEm: ?\Illuminate\Support\Carbon, dias: ?int, tom: string}
     */
    public function idadeDaBase(): array
    {
        // A linha mais recente da carga (índice `fonte, atualizado_em`): um registro só,
        // em vez de agregar as ~81 mil linhas a cada minuto da métrica.
        $linha = DB::table('cnpj_situacoes')->where('fonte', self::FONTE_BASE)
            ->orderByDesc('atualizado_em')
            ->first(['referencia', 'atualizado_em as carregada_em']);

        $carregadaEm = $linha ? \Illuminate\Support\Carbon::parse($linha->carregada_em) : null;

        $dias = $carregadaEm ? (int) $carregadaEm->diffInDays(now()) : null;

        return [
            'referencia' => $linha?->referencia,
            'carregadaEm' => $carregadaEm,
            'dias' => $dias,
            'tom' => match (true) {
                $dias === null, $dias > self::DIAS_ALARME => 'danger',
                $dias > self::DIAS_ATENCAO => 'warn',
                default => 'ok',
            },
        ];
    }

    /**
     * Situação, data, capital social e porte de cada CNPJ, para exibir. Só os conhecidos.
     * `porte` sai como rótulo pronto ("Pequeno porte"), nunca o código.
     *
     * @param  iterable<string>  $cnpjs  14 dígitos
     * @return array<string, array{situacao: string, data: ?string, capitalSocial: ?float, porte: ?string}>
     */
    public function detalhes(iterable $cnpjs): array
    {
        $resultado = [];

        foreach (array_chunk(array_values(array_filter(array_unique([...$cnpjs]))), 2000) as $lote) {
            DB::table('cnpj_situacoes')->whereIn('cnpj', array_map('strval', $lote))
                ->get(['cnpj', 'situacao', 'data_situacao', 'capital_social', 'porte'])
                ->each(function ($l) use (&$resultado) {
                    $resultado[$l->cnpj] = [
                        'situacao' => $l->situacao,
                        'data' => $l->data_situacao,
                        'capitalSocial' => $l->capital_social !== null ? (float) $l->capital_social : null,
                        'porte' => PorteEmpresa::rotulo($l->porte),
                    ];
                });
        }

        return $resultado;
    }

    /**
     * @param  iterable<string>  $cnpjs  14 dígitos
     * @return array<string, string> cnpj => situação (só os conhecidos)
     */
    public function situacoes(iterable $cnpjs): array
    {
        $resultado = [];

        foreach (array_chunk(array_values(array_unique([...$cnpjs])), 2000) as $lote) {
            $resultado += DB::table('cnpj_situacoes')->whereIn('cnpj', array_map('strval', $lote))
                ->pluck('situacao', 'cnpj')->all();
        }

        return $resultado;
    }

    /**
     * Grava a situação vinda do cartão consultado pela API. A última escrita vence:
     * o cartão costuma ser mais recente que a carga mensal.
     */
    /** Teto de consultas na hora por rodada de import (~0,5 s cada; ~3 min no pior caso). */
    public const MAXIMO_CONSULTAS_NA_HORA = 300;

    /** Fontes falhando em sequência: as APIs gratuitas estão bloqueando ou fora — parar. */
    private const FALHAS_SEGUIDAS_PARA_PARAR = 5;

    /**
     * Consulta NA HORA, pelas APIs do cartão CNPJ, os CNPJs que a base mensal ainda não
     * conhece — é o que deixa um lead novo entrar (ou ser barrado) na mesma rodada, em vez
     * de esperar a próxima carga da Receita (pedido do Tony, 2026-10-02).
     *
     * Grava em `cnpj_situacoes` pelo mesmo caminho do botão do cartão
     * (`CartaoCnpjService::consultar` → `registrarDoCartao`). CNPJ que TODAS as fontes dizem
     * não existir vira INEXISTENTE. Fonte fora do ar não marca nada: o CNPJ continua
     * desconhecido e é tentado na rodada seguinte.
     *
     * ⚠️ Não serve para massa (5 consultas/min na CNPJá, cota informal nas outras): passou
     * do teto, ou as fontes começaram a falhar em sequência, para — o resto espera.
     *
     * @param  list<string>  $cnpjs  14 dígitos, situação desconhecida
     * @return array{consultados: int, indisponiveis: int, restantes: int}
     */
    public function consultarDesconhecidos(array $cnpjs, int $maximo = self::MAXIMO_CONSULTAS_NA_HORA): array
    {
        $cartao = app(CartaoCnpjService::class);
        $consultados = $indisponiveis = $falhasSeguidas = 0;

        foreach (array_slice($cnpjs, 0, $maximo) as $cnpj) {
            if ($falhasSeguidas >= self::FALHAS_SEGUIDAS_PARA_PARAR) {
                break;
            }

            $consultados++;
            $resultado = $cartao->consultar((string) $cnpj);

            if ($resultado['status'] === CartaoCnpjService::NAO_ENCONTRADO) {
                $this->registrarDoCartao((string) $cnpj, self::INEXISTENTE, 'cartao');
                $falhasSeguidas = 0;
            } elseif ($resultado['status'] === CartaoCnpjService::INDISPONIVEL) {
                $indisponiveis++;
                $falhasSeguidas++;
            } else {
                $falhasSeguidas = 0;
            }
        }

        return [
            'consultados' => $consultados,
            'indisponiveis' => $indisponiveis,
            'restantes' => count($cnpjs) - $consultados,
        ];
    }

    /**
     * Capital e porte só são gravados quando o cartão os traz: fonte que não informa não
     * apaga o que a carga mensal já tinha.
     */
    public function registrarDoCartao(
        string $cnpj,
        ?string $situacao,
        string $fonte,
        ?string $dataSituacao = null,
        ?float $capitalSocial = null,
        ?string $porte = null,
    ): void {
        if (strlen($cnpj) !== 14 || blank($situacao)) {
            return;
        }

        $linha = [
            'cnpj' => $cnpj,
            'situacao' => $situacao,
            'data_situacao' => self::data($dataSituacao),
            'fonte' => $fonte,
            'referencia' => null,
            'atualizado_em' => now(),
            'capital_social' => $capitalSocial,
            'porte' => $porte,
        ];

        $atualizar = ['situacao', 'data_situacao', 'fonte', 'referencia', 'atualizado_em'];
        if ($capitalSocial !== null) {
            $atualizar[] = 'capital_social';
        }
        if ($porte !== null) {
            $atualizar[] = 'porte';
        }

        DB::table('cnpj_situacoes')->upsert([$linha], ['cnpj'], $atualizar);
    }

    /**
     * Acerta os leads da base de prospecção com a situação conhecida na Receita, nos dois
     * sentidos:
     *
     *   - CNPJ conhecido e irregular → `status = excluido`, com o carimbo
     *     `excluido_pela_receita_em`;
     *   - lead COM o carimbo cujo CNPJ voltou a ATIVA → volta para `ativo` e o carimbo
     *     some (decisão do Tony, 2026-10-01). A etapa do funil não muda.
     *
     * ⚠️ Lead excluído À MÃO não tem carimbo e nunca volta por aqui. O carimbo é a única
     * coisa que diferencia "a Receita tirou" de "alguém tirou".
     *
     * ⚠️ Marca `excluido` em vez de apagar: `observacoes.lead_id` e
     * `agendamentos_ligacoes.lead_id` são ON DELETE SET NULL, e apagar soltaria o
     * histórico do vendedor em silêncio. `Lead::visivel()` já esconde excluído em todas
     * as telas. Só as bases importadas (`Lead::ORIGENS_IMPORTADAS`: a antiga e a da
     * prospecção): manual e site são decisão de quem cadastrou.
     *
     * ⚠️ Situação DESCONHECIDA não mexe em nada, em nenhum dos sentidos.
     *
     * @return array{excluidos: array<string, int>, reativados: int}
     */
    public function sincronizarLeads(): array
    {
        $leads = DB::table('leads')->whereIn('origem', \App\Models\Lead::ORIGENS_IMPORTADAS)
            ->where(fn ($q) => $q->where('status', '!=', 'excluido')->orWhereNotNull('excluido_pela_receita_em'))
            ->whereNotNull('cnpj')
            ->get(['id', 'cnpj', 'status', 'excluido_pela_receita_em'])
            ->map(function ($l) {
                $l->digitos = preg_replace('/\D/', '', (string) $l->cnpj);

                return $l;
            })
            ->filter(fn ($l) => strlen($l->digitos) === 14);

        $situacoes = $this->situacoes($leads->pluck('digitos'));
        $excluir = [];
        $reativar = [];
        $porSituacao = [];

        foreach ($leads as $lead) {
            $situacao = $situacoes[$lead->digitos] ?? null;

            if ($lead->status !== 'excluido' && self::irregular($situacao)) {
                $excluir[] = $lead->id;
                $porSituacao[$situacao] = ($porSituacao[$situacao] ?? 0) + 1;
            /*
             * ⚠️ A condição do carimbo aqui é REDUNDANTE com o filtro da consulta acima, de
             * propósito: verificado por mutação, tirar só uma das duas não quebra nada, e
             * tirar as duas devolve ao CRM o lead que alguém excluiu à mão. Não "simplificar"
             * removendo a que parece sobrar.
             */
            } elseif ($lead->status === 'excluido' && $lead->excluido_pela_receita_em !== null && $situacao === self::ATIVA) {
                $reativar[] = $lead->id;
            }
        }

        $agora = now();

        foreach (array_chunk($excluir, 1000) as $lote) {
            DB::table('leads')->whereIn('id', $lote)
                ->update(['status' => 'excluido', 'excluido_pela_receita_em' => $agora, 'updated_at' => $agora]);
        }

        foreach (array_chunk($reativar, 1000) as $lote) {
            DB::table('leads')->whereIn('id', $lote)
                ->update(['status' => 'ativo', 'excluido_pela_receita_em' => null, 'updated_at' => $agora]);
        }

        ksort($porSituacao);

        return ['excluidos' => $porSituacao, 'reativados' => count($reativar)];
    }

    public static function data(?string $data): ?string
    {
        if (blank($data)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $data);

        // Base da Receita: AAAAMMDD; APIs: AAAA-MM-DD. "00000000" = sem data.
        if (strlen($digitos) !== 8 || (int) $digitos === 0) {
            return null;
        }

        return substr($digitos, 0, 4).'-'.substr($digitos, 4, 2).'-'.substr($digitos, 6, 2);
    }
}
