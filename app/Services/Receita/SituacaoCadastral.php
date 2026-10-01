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
    public function registrarDoCartao(string $cnpj, ?string $situacao, string $fonte, ?string $dataSituacao = null): void
    {
        if (strlen($cnpj) !== 14 || blank($situacao)) {
            return;
        }

        DB::table('cnpj_situacoes')->upsert([[
            'cnpj' => $cnpj,
            'situacao' => $situacao,
            'data_situacao' => self::data($dataSituacao),
            'fonte' => $fonte,
            'referencia' => null,
            'atualizado_em' => now(),
        ]], ['cnpj'], ['situacao', 'data_situacao', 'fonte', 'referencia', 'atualizado_em']);
    }

    /**
     * Tira do CRM os leads da base de prospecção cujo CNPJ é conhecido e não está ativo.
     *
     * ⚠️ Marca `status = excluido` em vez de apagar: `observacoes.lead_id` e
     * `agendamentos_ligacoes.lead_id` são ON DELETE SET NULL, e apagar soltaria o
     * histórico do vendedor em silêncio. `Lead::visivel()` já esconde excluído em todas
     * as telas. Só `origem = sistema`: manual e site são decisão de quem cadastrou.
     *
     * @return array<string, int> situação => leads excluídos
     */
    public function excluirLeadsNaoAtivos(): array
    {
        $leads = DB::table('leads')->where('origem', 'sistema')->where('status', '!=', 'excluido')
            ->whereNotNull('cnpj')->pluck('cnpj', 'id')
            ->map(fn ($cnpj) => preg_replace('/\D/', '', (string) $cnpj))
            ->filter(fn ($d) => strlen($d) === 14);

        $situacoes = $this->situacoes($leads->values());
        $porSituacao = [];
        $ids = [];

        foreach ($leads as $id => $cnpj) {
            $situacao = $situacoes[$cnpj] ?? null;

            if ($situacao !== null && ! self::permiteLead($situacao)) {
                $ids[] = $id;
                $porSituacao[$situacao] = ($porSituacao[$situacao] ?? 0) + 1;
            }
        }

        foreach (array_chunk($ids, 1000) as $lote) {
            DB::table('leads')->whereIn('id', $lote)->update(['status' => 'excluido', 'updated_at' => now()]);
        }

        ksort($porSituacao);

        return $porSituacao;
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
