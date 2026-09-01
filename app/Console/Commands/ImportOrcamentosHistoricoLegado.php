<?php

namespace App\Console\Commands;

use App\Models\VendedorPerfil;
use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Traz o histórico de orçamentos do PALMA legado (schema achatado: itens em JSON dentro de
 * um TEXT) para o normalizado do v2. Orçamento novo nasce direto na tela do v2 — este
 * comando só cobre o que veio de lá.
 *
 * ⚠️ REEXECUTÁVEL DESDE 2026-09-01, E ANTES NÃO ERA. A primeira versão fazia
 * `insertGetId` puro, sem truncate e sem chave de dedup: rodar de novo não traria só o que
 * falta, reinseriria o histórico inteiro por cima do que já estava, duplicando tudo sem
 * erro nenhum. Agora cada orçamento carrega o `legado_id` da origem.
 *
 * ⚠️ NÃO ATUALIZA o que já está ligado, de propósito — só insere o ausente. O legado é
 * dono do orçamento histórico, mas a tela do v2 deixa editar, e sobrescrever na
 * reimportação apagaria essa edição sem aviso. Mesmo raciocínio que tirou `display_name` e
 * `foto_perfil` do update do `legado:import-usuarios`.
 */
class ImportOrcamentosHistoricoLegado extends Command
{
    protected $signature = 'legado:import-orcamentos-historico
        {--fonte=homolog : homolog ou producao}
        {--dry-run : mostra o que faria, sem escrever nada}';

    protected $description = 'Traz do legado os orçamentos que ainda não existem no CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $dryRun = (bool) $this->option('dry-run');
        $pdo = LegadoConexao::pdo($fonte);

        $userIdPorCodVendedor = VendedorPerfil::query()->pluck('user_id', 'cod_vendedor');

        [$jaLigados, $adotaveis] = $this->indexarExistentes();
        $this->line(sprintf(
            'No v2: %d orçamentos já ligados ao legado, %d ainda sem legado_id.',
            $jaLigados->count(),
            array_sum(array_map('count', $adotaveis))
        ));

        $stmt = $pdo->query('SELECT * FROM ORCAMENTOS ORDER BY id');

        $agora = now();
        $inseridos = 0;
        $adotados = 0;
        $jaExistiam = 0;
        $semVendedor = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $legadoId = (int) $row['id'];

            if ($jaLigados->has($legadoId)) {
                $jaExistiam++;

                continue;
            }

            // Import anterior trouxe este orçamento antes de existir `legado_id`:
            // reaproveita a linha em vez de criar uma segunda cópia dela.
            $adotado = $this->adotar($adotaveis, $row);
            if ($adotado !== null) {
                $adotados++;
                if (! $dryRun) {
                    DB::table('orcamentos')->where('id', $adotado)->update(['legado_id' => $legadoId]);
                }

                continue;
            }

            $codVendedor = trim((string) $row['codigo_vendedor']);
            $userId = $userIdPorCodVendedor[$codVendedor] ?? null;

            if ($userId === null) {
                $semVendedor++;

                continue;
            }

            $inseridos++;
            if (! $dryRun) {
                $this->inserir($row, $legadoId, $userId, $agora);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Orçamentos inseridos: {$inseridos}");
        $this->line("Já existiam (ligados pelo legado_id): {$jaExistiam}");
        if ($adotados > 0) {
            $this->line("Adotados de import anterior (legado_id preenchido agora): {$adotados}");
        }
        if ($semVendedor > 0) {
            $this->warn("Ignorados (código de vendedor sem usuário real correspondente): {$semVendedor}");
        }

        return self::SUCCESS;
    }

    /**
     * Devolve [orçamentos já ligados por legado_id, índice de adoção dos não ligados].
     *
     * O índice de adoção é `chave natural => [ids do v2, em ordem]`. A chave natural (nome
     * do cliente + valor + data de criação) identifica sozinha 1.821 dos 1.860 orçamentos
     * já importados; as 16 chaves repetidas restantes são desempatadas por ORDEM: a versão
     * original percorria `ORDER BY id` e inseria em sequência, então a n-ésima linha do v2
     * dentro de uma chave corresponde ao n-ésimo id do legado dentro dela.
     *
     * ⚠️ Isso vale porque foi medido que NENHUM orçamento do v2 nasceu na tela até aqui —
     * todos têm par no legado. Depois do beta isso deixa de valer, e por isso a adoção só
     * existe para a carga que já está no banco: registro novo sempre nasce com legado_id
     * (importado) ou sem ele para sempre (nativo).
     */
    private function indexarExistentes(): array
    {
        $jaLigados = DB::table('orcamentos')->whereNotNull('legado_id')->pluck('id', 'legado_id');

        $adotaveis = [];
        DB::table('orcamentos')
            ->select('id', 'cliente_nome', 'valor_total', 'created_at')
            ->whereNull('legado_id')
            ->orderBy('id')
            ->cursor()
            ->each(function ($o) use (&$adotaveis) {
                $adotaveis[self::chaveNatural($o->cliente_nome, $o->valor_total, $o->created_at)][] = $o->id;
            });

        return [$jaLigados, $adotaveis];
    }

    private function adotar(array &$adotaveis, array $row): ?int
    {
        $chave = self::chaveNatural($row['cliente_nome'], $row['valor_total'], $row['data_criacao']);

        if (empty($adotaveis[$chave])) {
            return null;
        }

        $id = array_shift($adotaveis[$chave]);
        if ($adotaveis[$chave] === []) {
            unset($adotaveis[$chave]);
        }

        return $id;
    }

    private static function chaveNatural(mixed $nome, mixed $valor, mixed $criadoEm): string
    {
        return mb_strtolower(trim((string) $nome))
            .'|'.number_format((float) $valor, 2, '.', '')
            .'|'.substr((string) $criadoEm, 0, 19);
    }

    private function inserir(array $row, int $legadoId, int $userId, mixed $agora): void
    {
        $itens = json_decode((string) $row['itens_orcamento'], true);
        if (! is_array($itens)) {
            $itens = [];
        }

        $criadoEm = self::dataOuNull($row['data_criacao']) ?? $agora;

        $orcamentoId = DB::table('orcamentos')->insertGetId([
            'legado_id' => $legadoId,
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
