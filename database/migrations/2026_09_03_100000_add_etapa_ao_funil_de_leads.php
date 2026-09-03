<?php

use App\Models\Lead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa os dois eixos que viviam espremidos em `leads.status`.
 *
 * `status` era `ativo | inativo | convertido | excluido`. Isso descreve o REGISTRO, não a
 * NEGOCIAÇÃO — por isso "convertido" convivia com "inativo", que não são alternativas uma
 * da outra. Não dava para trabalhar um lead com isso: faltava a pergunta "já tratei esse,
 * joga pro próximo".
 *
 * Agora:
 * - `etapa`  → onde está na negociação (novo → em_contato → orcamento → negociacao),
 *              mais os desfechos `ganho` e `perdido`.
 * - `status` → só a lixeira (`ativo | excluido`). `Lead::scopeVisivel()` continua correto.
 *
 * ⚠️ Ganho e Perdido são valores do enum mas NÃO são colunas do quadro. Coluna de ganho
 * cresce para sempre e vira lixo visual; desfecho é ação, não coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('etapa', Lead::ETAPAS)->default(Lead::ETAPA_NOVO)->after('status');

            /*
             * ⚠️ NÃO usar `updated_at` para "parado há X dias": qualquer edição do lead o
             * toca (uma correção de telefone "reanimaria" um lead esquecido há meses), e o
             * indicador que faz alguém agir passaria a mentir.
             */
            $table->timestamp('etapa_alterada_em')->nullable()->after('etapa');

            $table->string('motivo_perda', 255)->nullable()->after('etapa_alterada_em');
        });

        /*
         * Deriva a etapa do que JÁ ESTÁ gravado, em vez de jogar 17 mil leads em "novo".
         * A ordem importa: o UPDATE de `em_contato` roda por último e só pega quem ainda
         * está `novo`, para não sobrescrever ganho/perdido.
         */
        DB::table('leads')->where('status', 'convertido')->update(['etapa' => Lead::ETAPA_GANHO]);
        DB::table('leads')->where('status', 'inativo')->update(['etapa' => Lead::ETAPA_PERDIDO]);

        // Quem já tem contato registrado não é mais "novo" — o dado para saber isso já
        // existe em `ligacoes`, e ignorá-lo faria o quadro nascer mentindo.
        DB::statement(
            'UPDATE leads SET etapa = ? WHERE etapa = ? AND EXISTS ('
            .'SELECT 1 FROM ligacoes WHERE ligacoes.lead_id = leads.id AND ligacoes.status <> ?)',
            [Lead::ETAPA_EM_CONTATO, Lead::ETAPA_NOVO, 'excluida'],
        );

        /*
         * ⚠️ Aproximação assumida: não existe registro de quando a etapa mudou, porque a
         * etapa não existia. `updated_at` é o menos errado disponível — então o "parado há
         * X dias" nasce APROXIMADO e só fica exato depois do primeiro movimento real.
         */
        DB::statement('UPDATE leads SET etapa_alterada_em = updated_at');

        // `inativo` e `convertido` deixam de ser estado de registro; viraram etapa.
        DB::table('leads')->whereIn('status', ['inativo', 'convertido'])->update(['status' => 'ativo']);

        DB::statement("ALTER TABLE leads MODIFY status ENUM('ativo','excluido') NOT NULL DEFAULT 'ativo'");

        Schema::table('leads', function (Blueprint $table) {
            /*
             * Cobre de uma vez as três leituras do quadro: a contagem por coluna
             * (GROUP BY etapa dentro do escopo), a ordenação de cada coluna
             * (etapa_alterada_em ASC, "mais parado primeiro") e o filtro "esquecidos".
             * Sem ele, abrir o funil no escopo admin varre as 17 mil linhas três vezes.
             */
            $table->index(['cod_vendedor', 'etapa', 'etapa_alterada_em'], 'leads_funil_idx');
        });

        /*
         * `(cod_vendedor, status)` vira redundante: `status` só tem dois valores agora e o
         * índice novo já começa por `cod_vendedor`. Mesmo tratamento que `faturamentos`
         * recebeu em 2026-08-31 — índice a mais custa escrita e RAM sem pagar leitura.
         */
        $this->dropSeExistir('leads_cod_vendedor_status_index');
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['cod_vendedor', 'status'], 'leads_cod_vendedor_status_index');
            $table->dropIndex('leads_funil_idx');
        });

        DB::statement("ALTER TABLE leads MODIFY status ENUM('ativo','inativo','convertido','excluido') NOT NULL DEFAULT 'ativo'");
        DB::table('leads')->where('etapa', Lead::ETAPA_GANHO)->update(['status' => 'convertido']);
        DB::table('leads')->where('etapa', Lead::ETAPA_PERDIDO)->update(['status' => 'inativo']);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['etapa', 'etapa_alterada_em', 'motivo_perda']);
        });
    }

    /** Dev e produção podem chegar aqui em estados diferentes — mesma lição da 110000. */
    private function dropSeExistir(string $indice): void
    {
        $existe = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['leads', $indice],
        );

        if ($existe) {
            DB::statement("ALTER TABLE leads DROP INDEX `{$indice}`");
        }
    }
};
