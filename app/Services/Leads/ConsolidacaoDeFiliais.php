<?php

namespace App\Services\Leads;

use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Um lead por EMPRESA (decisão do Tony, 13/10/2026).
 *
 * A base antiga de prospecção (`sistema`) cadastrou cada loja como um lead: a Festval tinha
 * 29, um por CNPJ. Na Visão Diretor e no funil a mesma empresa aparecia dezenas de vezes.
 * Em produção, 644 empresas tinham mais de uma filial como lead (2.396 leads).
 *
 * Empresa = os 8 primeiros dígitos do CNPJ, calculados aqui (Regra de ouro nº 3: raiz de
 * CNPJ nunca vira coluna). Lead sem CNPJ não entra.
 *
 * **Fica um**, pela ordem: ligado a uma rede (confirmado) → etapa já trabalhada no funil →
 * aberto à mão, pelo site ou pela prospecção (antes da base antiga) → com dono → a matriz
 * (`/0001`) → o mais antigo.
 *
 * **Os outros** passam o histórico para ele (as mesmas tabelas da `FusaoDeLeads`) e viram
 * `excluido` — NÃO são apagados. Não ganham o carimbo `excluido_pela_receita_em`, então a
 * Receita não os reativa; e o import, que casa pelo CNPJ inclusive com lead excluído, atualiza
 * os dados mas mantém o status. Por isso a consolidação não se desfaz sozinha.
 *
 * Roda no fim de todo `totvs:import-leads` (uma planilha com várias filiais de uma empresa
 * nova vira um lead só) e pelo comando `leads:consolidar-filiais`.
 */
class ConsolidacaoDeFiliais
{
    /** Tabelas com `lead_id`: o histórico das filiais que saem passa para a que fica. */
    private const REFERENCIAS = ['ligacoes', 'agendamentos_ligacoes', 'observacoes', 'orcamentos', 'marketing_wp_leads_raw'];

    /** Copiados de uma filial que sai só quando a que fica não tem. */
    private const CAMPOS_SE_VAZIO = ['cod_vendedor', 'nome_fantasia', 'email', 'telefone', 'endereco', 'cidade', 'estado', 'segmento'];

    /** Origens que alguém escolheu (à mão, site, planilha de prospecção) vêm antes da base antiga. */
    private const ORIGENS_ESCOLHIDAS = [Lead::ORIGEM_PROSPECCAO, Lead::ORIGEM_MANUAL, Lead::ORIGEM_WORDPRESS];

    /**
     * As empresas com mais de um lead visível e quem fica/sai em cada uma.
     *
     * @return list<array{empresa: string, fica: int, saem: list<int>}>
     */
    public function plano(): array
    {
        return $this->leadsComCnpj()
            ->groupBy('empresa')
            ->filter(fn (Collection $filiais) => $filiais->count() > 1)
            ->map(function (Collection $filiais, string $empresa) {
                $ordenadas = $filiais->sortBy(fn (object $l) => $this->prioridade($l))->values();

                return [
                    'empresa' => $empresa,
                    'fica' => $ordenadas->first()->id,
                    'saem' => $ordenadas->slice(1)->pluck('id')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Executa o plano. Uma transação por empresa: se uma falhar, as outras ficam feitas.
     *
     * @return array{empresas: int, excluidos: int}
     */
    public function consolidar(): array
    {
        $plano = $this->plano();

        foreach ($plano as $empresa) {
            $this->fundir($empresa['fica'], $empresa['saem']);
        }

        return [
            'empresas' => count($plano),
            'excluidos' => array_sum(array_map(fn ($e) => count($e['saem']), $plano)),
        ];
    }

    /** @param  list<int>  $saem */
    private function fundir(int $fica, array $saem): void
    {
        DB::transaction(function () use ($fica, $saem) {
            $leads = DB::table('leads')->whereIn('id', [$fica, ...$saem])->get()->keyBy('id');
            $principal = $leads->get($fica);
            $dados = [];

            foreach ($saem as $id) {
                $sai = $leads->get($id);

                foreach (self::CAMPOS_SE_VAZIO as $campo) {
                    if (blank($principal->{$campo}) && ! isset($dados[$campo]) && filled($sai->{$campo})) {
                        $dados[$campo] = $sai->{$campo};
                    }
                }

                // A ligação com a rede não pode se perder: se só a filial que sai estava ligada, passa.
                if ($principal->conta_estrategica_id === null && ! isset($dados['conta_estrategica_id'])
                    && $sai->conta_estrategica_id !== null && $sai->conta_vinculo === Lead::CONTA_CONFIRMADA) {
                    $dados['conta_estrategica_id'] = $sai->conta_estrategica_id;
                    $dados['conta_vinculo'] = Lead::CONTA_CONFIRMADA;
                }
            }

            foreach (self::REFERENCIAS as $tabela) {
                DB::table($tabela)->whereIn('lead_id', $saem)->update(['lead_id' => $fica]);
            }

            DB::table('leads')->whereIn('id', $saem)->update(['status' => 'excluido', 'updated_at' => now()]);

            if ($dados !== []) {
                DB::table('leads')->where('id', $fica)->update([...$dados, 'updated_at' => now()]);
            }
        });
    }

    /** Menor = fica. */
    private function prioridade(object $l): array
    {
        return [
            $l->conta_vinculo === Lead::CONTA_CONFIRMADA ? 0 : 1,
            $l->etapa === 'novo' ? 1 : 0,
            in_array($l->origem, self::ORIGENS_ESCOLHIDAS, true) ? 0 : 1,
            blank($l->cod_vendedor) ? 1 : 0,
            $l->filial === '0001' ? 0 : 1,
            $l->id,
        ];
    }

    /** Leads visíveis com CNPJ válido, com a empresa (raiz) e a filial calculadas. */
    private function leadsComCnpj(): Collection
    {
        return DB::table('leads')
            ->where('status', '!=', 'excluido')
            ->whereNotNull('cnpj')
            ->orderBy('id')
            ->get(['id', 'cnpj', 'origem', 'etapa', 'cod_vendedor', 'conta_estrategica_id', 'conta_vinculo'])
            ->map(function (object $l) {
                $d = preg_replace('/\D/', '', (string) $l->cnpj);
                if (in_array(strlen($d), [12, 13], true)) {
                    $d = str_pad($d, 14, '0', STR_PAD_LEFT);
                }
                if (strlen($d) !== 14) {
                    return null;
                }
                $l->empresa = substr($d, 0, 8);
                $l->filial = substr($d, 8, 4);

                return $l;
            })
            ->filter()
            ->values();
    }
}
