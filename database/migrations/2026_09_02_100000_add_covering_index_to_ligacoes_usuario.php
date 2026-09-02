<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Torna COBERTO o índice que a "Última ligação" da Visão do Gestor consulta.
 *
 * `(usuario_id, data_ligacao)` -> `+ status`: 969 ms -> 154 ms (6,3x) com 289 mil
 * contatos. Sem `status` no índice, o `<> 'excluida'` obrigava a ir à tabela linha a
 * linha e o EXPLAIN varria as 294 mil entradas do índice.
 *
 * ⚠️ Esta consulta JÁ ESTAVA LENTA antes de qualquer feature nova, e ninguém tinha
 * medido — só apareceu quando a tabela foi populada com volume realista. Entra nesta
 * leva porque os botões de WhatsApp e e-mail MULTIPLICAM as linhas desta tabela: o que
 * hoje é um contato por cliente vira três.
 *
 * ⚠️ NÃO É ÍNDICE NOVO: é o mesmo, com uma coluna a mais no fim. O prefixo
 * continua idêntico, então tudo que usava o antigo — a chave estrangeira de
 * `usuario_id` inclusive — segue servido pelo novo.
 *
 * É a mesma distinção de 2026-08-31: índice ESCOLHIDO e índice SUFICIENTE são coisas
 * diferentes. O `key:` do EXPLAIN já apontava pro índice certo e a consulta continuava
 * cara — quem denuncia é o `Extra:`, não o `key:`.
 *
 * (Próximo passo já medido, caso 154 ms incomode: trocar o GROUP BY de
 * `VisaoGestorController::ultimaLigacao()` por subconsulta correlata por vendedor —
 * mede 102 ms. Não foi feito porque exige mexer no controller e o ganho é 1,5x contra
 * os 6,3x do índice.)
 */
return new class extends Migration
{
    /**
     * Trocas a aplicar: [nome novo, colunas do novo, nome antigo, colunas do antigo].
     */
    private const TROCAS = [
        ['ligacoes_usuario_atividade_index', ['usuario_id', 'data_ligacao', 'status'],
            'ligacoes_usuario_id_data_ligacao_index', ['usuario_id', 'data_ligacao']],
    ];

    public function up(): void
    {
        foreach (self::TROCAS as [$novo, $colunasNovo, $antigo, ]) {
            $this->trocar($novo, $colunasNovo, $antigo);
        }
    }

    public function down(): void
    {
        foreach (self::TROCAS as [$novo, , $antigo, $colunasAntigo]) {
            $this->trocar($antigo, $colunasAntigo, $novo);
        }
    }

    /**
     * Cria `$criar` (se ainda não existir) e só então remove `$remover`.
     *
     * ⚠️ A ORDEM É OBRIGATÓRIA: `cliente_id` e `usuario_id` têm chave estrangeira, e
     * o MySQL recusa remover o único índice que a sustenta ("Cannot drop index ...:
     * needed in a foreign key constraint").
     *
     * ⚠️ IDEMPOTENTE de propósito. Rodar duas vezes, ou rodar num banco que já está
     * meio-caminho, tem que ser inofensivo: é exatamente o modo de falha da migration
     * 090000 (editada depois de aplicada, ficou divergente entre dev e produção) que a
     * 110000 teve que consertar.
     */
    private function trocar(string $criar, array $colunas, string $remover): void
    {
        if (! $this->existe($criar)) {
            Schema::table('ligacoes', fn (Blueprint $t) => $t->index($colunas, $criar));
        }

        if ($this->existe($remover)) {
            Schema::table('ligacoes', fn (Blueprint $t) => $t->dropIndex($remover));
        }
    }

    private function existe(string $indice): bool
    {
        return DB::select('SHOW INDEX FROM ligacoes WHERE Key_name = ?', [$indice]) !== [];
    }
};
