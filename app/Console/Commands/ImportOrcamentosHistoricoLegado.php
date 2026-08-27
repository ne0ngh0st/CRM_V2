<?php

namespace App\Console\Commands;

use App\Models\VendedorPerfil;
use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Migração PONTUAL (não é rotina recorrente): schema de orçamento do legado é achatado
 * (itens em JSON dentro de um TEXT), incompatível com o normalizado do v2. Orçamento novo
 * já nasce direto na tela do v2 — isto só traz o histórico de uma vez.
 */
class ImportOrcamentosHistoricoLegado extends Command
{
    protected $signature = 'legado:import-orcamentos-historico {--fonte=homolog : homolog ou producao}';

    protected $description = 'Migração pontual do histórico de orçamentos do legado pro CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $pdo = LegadoConexao::pdo($fonte);

        $userIdPorCodVendedor = VendedorPerfil::query()->pluck('user_id', 'cod_vendedor');

        $stmt = $pdo->query('SELECT * FROM ORCAMENTOS ORDER BY id');

        $agora = now();
        $total = 0;
        $semVendedor = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $codVendedor = trim((string) $row['codigo_vendedor']);
            $userId = $userIdPorCodVendedor[$codVendedor] ?? null;

            if ($userId === null) {
                $semVendedor++;

                continue;
            }

            $itens = json_decode((string) $row['itens_orcamento'], true);
            if (! is_array($itens)) {
                $itens = [];
            }

            $criadoEm = self::dataOuNull($row['data_criacao']) ?? $agora;

            $orcamentoId = DB::table('orcamentos')->insertGetId([
                'user_id' => $userId,
                'cliente_nome' => trim((string) $row['cliente_nome']),
                'cliente_cnpj' => self::valorOuNull($row['cliente_cnpj']),
                'cliente_contato' => self::valorOuNull($row['cliente_contato']),
                'forma_pagamento' => self::valorOuNull($row['forma_pagamento']),
                'valor_total' => $row['valor_total'] ?? 0,
                'data_validade' => self::dataOuNull($row['data_validade']),
                'desconto_pct_max' => $row['maior_desconto_pct'] ?? 0,
                'nivel_aprovacao' => in_array($row['nivel_aprovacao_necessario'], ['nenhum', 'supervisor', 'diretor'], true)
                    ? $row['nivel_aprovacao_necessario'] : 'nenhum',
                'status_gestor' => in_array($row['status_gestor'], ['pendente', 'aprovado', 'rejeitado'], true)
                    ? $row['status_gestor'] : 'pendente',
                // Legado só guarda o PERFIL de quem aprovou (texto), não o usuário — sem
                // usuário real pra apontar, aprovado_por_id fica null (não inventar).
                'aprovado_por_id' => null,
                'aprovado_em' => self::dataOuNull($row['data_aprovacao_gestor']),
                'motivo_rejeicao' => self::valorOuNull($row['motivo_recusa']),
                'tipo_produto_servico' => self::valorOuNull($row['tipo_produto_servico']) ?? 'produto',
                'observacoes' => self::valorOuNull($row['observacoes']),
                'variacao_producao_personalizado' => self::valorOuNull($row['variacao_produtos']),
                'prazo_producao' => self::valorOuNull($row['prazo_producao']),
                'garantia_imagem' => self::valorOuNull($row['garantia_imagem']),
                'texto_importante' => self::valorOuNull($row['texto_importante']),
                'created_at' => $criadoEm,
                'updated_at' => $criadoEm,
            ]);

            $linhasItens = [];
            foreach ($itens as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $linhasItens[] = [
                    'orcamento_id' => $orcamentoId,
                    'cod_produto' => self::valorOuNull($item['item'] ?? null),
                    'descricao' => trim((string) ($item['descricao'] ?? 'Item sem descrição')),
                    'quantidade' => $item['quantidade'] ?? 0,
                    'valor_unitario' => $item['valor_unitario'] ?? 0,
                    'valor_total' => $item['valor_total'] ?? 0,
                    'created_at' => $criadoEm,
                    'updated_at' => $criadoEm,
                ];
            }

            if ($linhasItens !== []) {
                DB::table('orcamento_itens')->insert($linhasItens);
            }

            $total++;
        }

        $this->info("Orçamentos importados: {$total}");
        if ($semVendedor > 0) {
            $this->warn("Ignorados (código de vendedor sem usuário real correspondente): {$semVendedor}");
        }

        return self::SUCCESS;
    }

    private static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    private static function dataOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }
}
