<?php

use App\Services\Pedidos\StatusPedidoResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O status do pedido deixa de ser constante e passa a vir do último movimento do TOTVS.
 *
 * A migration de 2026-07-29 (`add_pendente_totvs_status_to_pedidos_table`) resolveu o
 * problema certo do jeito conservador: em vez de inventar uma tradução do texto livre,
 * marcou tudo como "aguardando classificação". O efeito colateral, medido em produção em
 * 2026-09-09, foi uma coluna sem informação nenhuma — 3.478 pedidos em aberto, TODOS com
 * o mesmo valor, ao lado de outra coluna que já dizia aberto/faturado.
 *
 * O que mudou não foi a opinião, foi a evidência: contados os moldes do `HISTORICO` no
 * arquivo real, 99,7% dos pedidos caem em 11 frases-molde estáveis. Ver
 * {@see StatusPedidoResolver} para os números e para por que isto não repete a gambiarra
 * de regex do legado.
 *
 * ⚠️ O VALOR `pendente_totvs` CONTINUA NO ENUM, e é de propósito. Ele deixou de
 * significar "ninguém classificou ainda" e passou a significar "o CRM não reconheceu
 * este movimento" — o fallback honesto. Mantê-lo evita um UPDATE em massa nas 3.478
 * linhas de produção que não compraria nada.
 *
 * ⚠️ SAEM DO ENUM `bloqueio` e `wms`: nunca foram escritos por nenhum import (conferido
 * no banco de produção — zero linhas), só pelo `PedidoSeeder`. O UPDATE abaixo converte
 * o que exista em dev antes do ALTER, porque o MySQL transforma valor fora do enum em
 * string vazia sem avisar.
 */
return new class extends Migration
{
    /**
     * Os valores que sumiram do enum → para onde vão.
     *
     * Só alcança banco de desenvolvimento semeado pelo `PedidoSeeder`. Em produção o
     * UPDATE não toca nenhuma linha, o que foi verificado antes de escrever isto.
     */
    private const CONVERSOES = [
        'bloqueio' => StatusPedidoResolver::BLOQUEIO_ESTOQUE,
        'wms' => StatusPedidoResolver::SEPARACAO,
    ];

    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            /*
             * O texto cru do último movimento, exatamente como o TOTVS mandou.
             *
             * ⚠️ Existe para que a classificação NUNCA seja destrutiva: o pedido cujo
             * molde o resolver não reconhece perde a pill, mas não perde a informação —
             * a frase aparece ao expandir a linha. É também o que permite reclassificar
             * o histórico sem reimportar o arquivo, quando um molde novo for aprendido.
             */
            $table->string('historico_totvs', 500)->nullable()->after('status');

            // Quando aquele movimento aconteceu (DATA_HIST + HORA_HIST do relatório).
            // Sem isto, "em separação" não diz se foi hoje de manhã ou há três semanas.
            $table->dateTime('historico_em')->nullable()->after('historico_totvs');
        });

        foreach (self::CONVERSOES as $antigo => $novo) {
            DB::table('pedidos')->where('status', $antigo)->update(['status' => $novo]);
        }

        DB::statement($this->alterEnum(StatusPedidoResolver::todos(), StatusPedidoResolver::DESCONHECIDO));
    }

    public function down(): void
    {
        // Sem os status novos o enum antigo não os aceita; tudo o que era etapa vira o
        // valor neutro de antes, que é exatamente o estado ao qual se está voltando.
        DB::table('pedidos')
            ->whereNotIn('status', ['faturado', StatusPedidoResolver::DESCONHECIDO])
            ->update(['status' => StatusPedidoResolver::DESCONHECIDO]);

        DB::statement($this->alterEnum(
            ['separacao', 'bloqueio', 'wms', 'liberado', 'faturado', 'pendente_totvs'],
            'separacao'
        ));

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['historico_totvs', 'historico_em']);
        });
    }

    /**
     * @param  list<string>  $valores
     */
    private function alterEnum(array $valores, string $default): string
    {
        $lista = implode(', ', array_map(fn (string $v) => "'{$v}'", $valores));

        return "ALTER TABLE pedidos MODIFY status ENUM({$lista}) NOT NULL DEFAULT '{$default}'";
    }
};
