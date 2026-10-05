<?php

namespace App\Services\VisaoDiretor;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Uma conta-alvo, um lead. Junta o lead que a diretoria abriu pelo "Gerar lead" (manual,
 * SEM CNPJ, com dono) com o lead da prospecção que chegou depois para a mesma rede (com
 * CNPJ, normalmente sem dono).
 *
 * Achado em produção em 2026-10-05: 8 contas com o par — "AGAFARMA" aberto pela diretoria
 * em 28/09 e "Agafarma" (01.569.100/0001-04) importado em 02/10, ligados à mesma conta.
 * O vendedor via a mesma empresa duas vezes, uma com dono e outra sem.
 *
 * **Fica o da diretoria** — tem o dono escolhido, a observação de contexto e o aviso no
 * sino. Ele herda o CNPJ e os dados cadastrais; tudo que apontava para o outro (contatos,
 * agendamentos, observações, orçamentos, captura do site) passa para ele; o outro some.
 *
 * ⚠️ Fica como `manual` de propósito: o import ignora CNPJ que já é lead manual, então a
 * duplicata não renasce na rodada seguinte, e a planilha da prospecção não sobrescreve o
 * dono que a diretoria escolheu.
 *
 * ⚠️ Só funde quando o da diretoria NÃO tem CNPJ. Dois leads com CNPJs diferentes na
 * mesma conta são duas empresas (filiais com cadastro próprio), não duplicata.
 */
class FusaoDeLeads
{
    /** Tabelas com `lead_id` — todas `nullOnDelete`, então esquecer uma solta o histórico. */
    private const REFERENCIAS = ['ligacoes', 'agendamentos_ligacoes', 'observacoes', 'orcamentos', 'marketing_wp_leads_raw'];

    /** Copiados do lead que sai só quando o que fica não tem. */
    private const CAMPOS_SE_VAZIO = ['nome_fantasia', 'email', 'telefone', 'endereco', 'cidade', 'estado'];

    /**
     * Procura os pares em TODAS as contas e funde. Idempotente: sem par, não faz nada.
     *
     * @return list<array{conta: string, fica: int, sai: int, cnpj: string}>
     */
    public function fundirDuplicadosDasContas(bool $dryRun = false): array
    {
        $ligados = Lead::query()->visivel()
            ->where('conta_vinculo', Lead::CONTA_CONFIRMADA)
            ->whereNotNull('conta_estrategica_id')
            ->with('contaEstrategica:id,nome')
            ->orderBy('id')
            ->get()
            ->groupBy('conta_estrategica_id');

        $pares = [];

        foreach ($ligados as $leads) {
            $daDiretoria = $leads->first(fn (Lead $l) => $l->origem === Lead::ORIGEM_MANUAL && blank($l->cnpj));
            $comCnpj = $leads->first(fn (Lead $l) => filled($l->cnpj));

            if (! $daDiretoria || ! $comCnpj) {
                continue;
            }

            $pares[] = [
                'conta' => $daDiretoria->contaEstrategica?->nome ?? '',
                'fica' => $daDiretoria->id,
                'sai' => $comCnpj->id,
                'cnpj' => (string) $comCnpj->cnpj,
            ];

            if (! $dryRun) {
                $this->fundir($daDiretoria, $comCnpj);
            }
        }

        return $pares;
    }

    public function fundir(Lead $fica, Lead $sai): void
    {
        DB::transaction(function () use ($fica, $sai) {
            $dados = ['cnpj' => $sai->cnpj];

            // A razão social do da diretoria é o nome da conta; a do outro é a legal.
            if (filled($sai->razao_social)) {
                $dados['razao_social'] = $sai->razao_social;
            }

            foreach (self::CAMPOS_SE_VAZIO as $campo) {
                if (blank($fica->{$campo}) && filled($sai->{$campo})) {
                    $dados[$campo] = $sai->{$campo};
                }
            }

            foreach (self::REFERENCIAS as $tabela) {
                DB::table($tabela)->where('lead_id', $sai->id)->update(['lead_id' => $fica->id]);
            }

            // Apaga antes de gravar o CNPJ: se um dia houver índice único em `cnpj`, a ordem inversa quebra.
            DB::table('leads')->where('id', $sai->id)->delete();
            DB::table('leads')->where('id', $fica->id)->update([...$dados, 'updated_at' => now()]);
        });
    }
}
