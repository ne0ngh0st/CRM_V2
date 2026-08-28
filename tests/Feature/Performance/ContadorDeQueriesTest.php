<?php

namespace Tests\Feature\Performance;

use App\Support\Perf\ContadorDeQueries;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trava o comportamento do instrumento de medição.
 *
 * Se o contador estiver errado, TODA conclusão de performance tirada dele está errada
 * junto — inclusive as que vão embasar decisão de infra. Por isso ele tem teste próprio.
 */
class ContadorDeQueriesTest extends TestCase
{
    /**
     * O teste que existe por causa de um bug real.
     *
     * A versão anterior (privada em SimulacaoUsuarioTest) registrava um `DB::listen` a
     * cada chamada. Como listener não pode ser removido, a 2ª medição também incrementava
     * o contador da 1ª — que já tinha sido lido, então passava despercebido, mas em
     * qualquer asserção absoluta viraria um número inflado e inexplicável.
     */
    public function test_medicoes_repetidas_nao_contaminam_umas_as_outras(): void
    {
        $umaQuery = fn () => DB::select('select 1');

        $primeira = ContadorDeQueries::contar($umaQuery);
        $segunda = ContadorDeQueries::contar($umaQuery);
        $terceira = ContadorDeQueries::contar($umaQuery);

        $this->assertSame(1, $primeira);
        $this->assertSame(1, $segunda, 'A 2ª medição divergiu da 1ª — listener acumulando.');
        $this->assertSame(1, $terceira, 'A 3ª medição divergiu — listener acumulando.');
    }

    public function test_conta_apenas_as_queries_de_dentro_da_acao(): void
    {
        DB::select('select 1');

        $medicao = ContadorDeQueries::medir(function () {
            DB::select('select 1');
            DB::select('select 2');
        });

        DB::select('select 3');

        $this->assertSame(2, $medicao->queries);
    }

    public function test_medicao_aninhada_conta_cada_nivel_separadamente(): void
    {
        $interna = null;

        $externa = ContadorDeQueries::medir(function () use (&$interna) {
            DB::select('select 1');

            $interna = ContadorDeQueries::medir(fn () => DB::select('select 2'));

            DB::select('select 3');
        });

        $this->assertSame(1, $interna->queries, 'A medição interna deveria ver só a própria query.');
        $this->assertSame(3, $externa->queries, 'A medição externa deveria ver as três.');
    }

    public function test_devolve_o_retorno_da_acao_e_mede_o_payload_de_uma_resposta(): void
    {
        $medicao = ContadorDeQueries::medir(fn () => response('doze bytes!'));

        $this->assertSame(11, $medicao->bytesPayload);
        $this->assertSame(200, $medicao->resultado->getStatusCode());
    }

    public function test_zera_o_payload_quando_a_acao_nao_devolve_resposta(): void
    {
        $medicao = ContadorDeQueries::medir(fn () => 'só uma string');

        $this->assertSame(0, $medicao->bytesPayload);
    }
}
