<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * ⚠️ PREFIRA `totvs:import-leads`. Este comando APAGA todos os leads `origem=sistema` e
 * reinsere, o que troca o `id` de cada um. Como `observacoes.lead_id` e
 * `agendamentos_ligacoes.lead_id` são ON DELETE SET NULL, cada execução desgarra as
 * observações dos seus leads em silêncio — hoje são 575 apontando para lead.
 *
 * Também traz a base sem deduplicar (20.568 linhas para 17.154 CNPJs, então o vendedor
 * vê a mesma empresa duas vezes) e reescreve o `status`, devolvendo para "ativo" o lead
 * que alguém marcou como convertido ou excluído.
 *
 * Fica porque o espelho do v1 ainda é a fonte de alguns domínios; o substituto lê o
 * arquivo direto e adota o lead pelo CNPJ, preservando o id.
 */
class ImportLeadsLegado extends Command
{
    protected $signature = 'legado:import-leads {--fonte=homolog : homolog ou producao}';

    protected $description = 'Import de leads (base_leads, filtrado por MARCAÇÃO PROSPECT) do TOTVS pro CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $pdo = LegadoConexao::pdo($fonte);

        $stmt = $pdo->query(
            "SELECT cnpj, RAZAOSOCIAL, NOMEFANTASIA, nomefinal, Email, TelefonePrincipalFINAL, "
            .'endereoCNPJJA, CIDADEarrumada, CIDADE, UF, CodigoVendedor, projeoRms '
            ."FROM BASE_LEADS WHERE UPPER(TRIM(MARCAOPROSPECT)) = 'SAI PROSPECT'"
        );

        $agora = now();
        $linhas = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $razaoSocial = self::valorOuNull($row['RAZAOSOCIAL']) ?? self::valorOuNull($row['nomefinal']);
            if ($razaoSocial === null) {
                continue;
            }

            $linhas[] = [
                'origem' => 'sistema',
                'user_id' => null,
                'cod_vendedor' => self::valorOuNull($row['CodigoVendedor']),
                'nome' => self::valorOuNull($row['nomefinal']) ?? $razaoSocial,
                'razao_social' => $razaoSocial,
                'nome_fantasia' => self::valorOuNull($row['NOMEFANTASIA']),
                'cnpj' => self::valorOuNull($row['cnpj']),
                'email' => self::valorOuNull($row['Email']),
                'telefone' => self::valorOuNull($row['TelefonePrincipalFINAL']),
                'endereco' => self::valorOuNull($row['endereoCNPJJA']),
                'cidade' => self::valorOuNull($row['CIDADEarrumada']) ?? self::valorOuNull($row['CIDADE']),
                'estado' => self::valorOuNull($row['UF']),
                // Sem de-para de segmento pra lead na fonte atual — não inventar (decisão do Tony).
                'segmento' => null,
                'valor_estimado' => self::valorPositivoOuNull($row['projeoRms']),
                'status' => 'ativo',
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        // Nunca mexe em origem=manual nem origem=wordpress — manual é dado que o
        // vendedor cadastrou pela tela; wordpress veio do site e não é do TOTVS.
        $removidos = DB::table('leads')->where('origem', 'sistema')->delete();
        $this->info("Leads origem=sistema removidos: {$removidos} (origem=manual e wordpress preservados).");

        $total = 0;
        foreach (array_chunk($linhas, 1000) as $lote) {
            DB::table('leads')->insert($lote);
            $total += count($lote);
        }

        $this->info("Leads importados: {$total}");

        return self::SUCCESS;
    }

    private static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    private static function valorPositivoOuNull(mixed $valor): ?float
    {
        $valor = (float) ($valor ?? 0);

        return $valor > 0 ? $valor : null;
    }
}
