<?php

namespace Tests\Feature\Performance;

use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use App\Support\Perf\ContadorDeQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O teste que dá confiança ao cache warming da Fase 3.
 *
 * A pergunta que ele responde é uma só: **depois de aquecer, o controller lê do cache?**
 *
 * Aquecer numa chave e ler de outra é o modo de falha mais silencioso de todo o plano de
 * performance — não gera erro, não gera log, o job reporta sucesso e a página continua
 * lenta. A verificação é contar queries: se o aquecimento funcionou, a leitura seguinte
 * faz ZERO. Qualquer número acima disso significa que a chave divergiu.
 *
 * Cada caso relê com a lista de vendedores EMBARALHADA, porque a origem real dessa
 * divergência era `implode()` sem `sort()`.
 */
class AquecimentoDeCacheTest extends TestCase
{
    use RefreshDatabase;

    private const CODIGOS = ['000123', '000456', '000789'];

    private const IDS = [7, 3, 11];

    private function blocos(): DashboardBlocos
    {
        return app(DashboardBlocos::class);
    }

    public function test_carteira_segmento_aquecida_nao_consulta_o_banco(): void
    {
        $this->assertLeituraSemQueries(
            fn (DashboardBlocos $b, array $cods) => $b->carteiraSegmento(ChaveEscopo::deCodVendedores($cods), $cods),
        );
    }

    public function test_pedidos_atencao_aquecido_nao_consulta_o_banco(): void
    {
        $this->assertLeituraSemQueries(
            fn (DashboardBlocos $b, array $cods) => $b->pedidosAtencao(ChaveEscopo::deCodVendedores($cods), $cods),
        );
    }

    public function test_faturamento_comparacao_aquecido_nao_consulta_o_banco(): void
    {
        $this->assertLeituraSemQueries(
            fn (DashboardBlocos $b, array $cods) => $b->faturamentoComparacao(ChaveEscopo::deCodVendedores($cods), $cods),
        );
    }

    public function test_meta_gauge_aquecido_nao_consulta_o_banco(): void
    {
        $this->assertLeituraSemQueries(
            fn (DashboardBlocos $b, array $cods) => $b->metaGauge(ChaveEscopo::deCodVendedores($cods), $cods),
        );
    }

    public function test_orcamentos_stats_aquecido_nao_consulta_o_banco(): void
    {
        $blocos = $this->blocos();

        $blocos->comRecalculoForcado()->orcamentosStats(ChaveEscopo::deUsuarioIds(self::IDS), self::IDS);

        $embaralhados = array_reverse(self::IDS);
        $medicao = ContadorDeQueries::medir(
            fn () => $blocos->orcamentosStats(ChaveEscopo::deUsuarioIds($embaralhados), $embaralhados),
        );

        $this->assertSame(0, $medicao->queries, $this->mensagem($medicao->queries));
    }

    public function test_escopo_de_empresa_inteira_tambem_aquece(): void
    {
        $blocos = $this->blocos();

        $blocos->comRecalculoForcado()->carteiraSegmento(ChaveEscopo::deCodVendedores(null), null);

        $medicao = ContadorDeQueries::medir(
            fn () => $blocos->carteiraSegmento(ChaveEscopo::deCodVendedores(null), null),
        );

        $this->assertSame(0, $medicao->queries, $this->mensagem($medicao->queries));
    }

    public function test_aquecer_um_escopo_nao_serve_outro(): void
    {
        $blocos = $this->blocos();

        // Aquece a empresa inteira...
        $blocos->comRecalculoForcado()->carteiraSegmento(ChaveEscopo::deCodVendedores(null), null);

        // ...e lê o escopo de um vendedor: tem que ir ao banco. Se não fosse, o vendedor
        // estaria vendo os números da empresa inteira — vazamento de dado entre escopos.
        $medicao = ContadorDeQueries::medir(
            fn () => $blocos->carteiraSegmento(ChaveEscopo::deCodVendedores(['000123']), ['000123']),
        );

        $this->assertGreaterThan(
            0,
            $medicao->queries,
            'O cache de um escopo respondeu por outro — os dados de um vendedor viriam errados.',
        );
    }

    /**
     * Aquece com a lista original e relê com ela embaralhada.
     *
     * @param  \Closure(DashboardBlocos, array<string>): mixed  $bloco
     */
    private function assertLeituraSemQueries(\Closure $bloco): void
    {
        $blocos = $this->blocos();

        $bloco($blocos->comRecalculoForcado(), self::CODIGOS);

        $embaralhados = array_reverse(self::CODIGOS);
        $medicao = ContadorDeQueries::medir(fn () => $bloco($blocos, $embaralhados));

        $this->assertSame(0, $medicao->queries, $this->mensagem($medicao->queries));
    }

    private function mensagem(int $queries): string
    {
        return "Esperava 0 queries após o aquecimento, veio {$queries}. ".
            'A chave gravada não é a mesma que a leitura procura — provavelmente a '.
            'normalização do escopo (ordem/duplicata/tipo) divergiu em algum ponto.';
    }
}
