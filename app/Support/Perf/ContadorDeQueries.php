<?php

namespace App\Support\Perf;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mede o custo de um trecho de código: queries, tempo de SQL, memória e payload.
 *
 * Usado pelo comando `perf:baseline` e pelos testes de orçamento de queries
 * (tests/Feature/Performance/). Ver docs/performance.md.
 *
 * ⚠️ POR QUE O LISTENER É REGISTRADO UMA VEZ SÓ:
 * `DB::listen()` não tem como ser removido. A primeira versão desta lógica vivia
 * privada em SimulacaoUsuarioTest e registrava um listener novo a cada chamada —
 * então duas medições no mesmo processo deixavam o primeiro contador vivo,
 * incrementando em silêncio depois de já ter sido lido. Aqui o listener é único
 * (guardado por $registrado) e só acumula enquanto houver coletor na pilha, o que
 * elimina o problema por construção em vez de por disciplina.
 *
 * Medições aninhadas funcionam: cada coletor ativo recebe o evento, então a de fora
 * conta tudo e a de dentro conta só o seu trecho.
 */
final class ContadorDeQueries
{
    /**
     * Coletores ativos, indexados. Cada um: ['queries' => int, 'msSql' => float, 'sqls' => list<string>].
     *
     * @var array<int, array{queries: int, msSql: float, sqls: list<string>}>
     */
    private static array $coletores = [];

    private static int $proximoIndice = 0;

    /**
     * Container para o qual o listener já foi registrado.
     *
     * ⚠️ NÃO trocar por um bool. Entre testes o Laravel chama refreshApplication(), que
     * cria um container e um event dispatcher NOVOS — e os listeners do antigo morrem
     * junto. Uma flag booleana estática sobreviveria a isso e diria "já registrei",
     * então do 2º teste em diante o contador mediria zero em silêncio. Comparando a
     * instância, o registro se refaz sozinho sempre que a aplicação é recriada.
     */
    private static ?object $containerRegistrado = null;

    /**
     * Executa $acao medindo o que ela custou.
     *
     * @param  bool  $capturarSql  guarda o texto de cada query (para depurar N+1). Custa memória — não usar em laço grande.
     */
    public static function medir(Closure $acao, bool $capturarSql = false): Medicao
    {
        self::registrarUmaVez();

        $indice = self::$proximoIndice++;
        self::$coletores[$indice] = ['queries' => 0, 'msSql' => 0.0, 'sqls' => []];

        // Zera o pico para medir o desta ação, não o acumulado do processo.
        // ⚠️ O pico é global: uma medição aninhada reseta o da externa também.
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $inicio = hrtime(true);

        try {
            $resultado = $acao();
        } finally {
            $msWall = (hrtime(true) - $inicio) / 1_000_000;
            $dados = self::$coletores[$indice];
            unset(self::$coletores[$indice]);
        }

        return new Medicao(
            queries: $dados['queries'],
            msSql: round($dados['msSql'], 2),
            msWall: round($msWall, 2),
            picoMemoriaBytes: memory_get_peak_usage(true),
            bytesPayload: self::tamanhoDoCorpo($resultado),
            sqls: $capturarSql ? $dados['sqls'] : [],
            resultado: $resultado,
        );
    }

    /**
     * Só o número de queries — atalho para asserções de teste.
     */
    public static function contar(Closure $acao): int
    {
        return self::medir($acao)->queries;
    }

    private static function registrarUmaVez(): void
    {
        $container = Container::getInstance();

        if (self::$containerRegistrado === $container) {
            return;
        }

        self::$containerRegistrado = $container;

        // Aplicação recriada = coletores da anterior são lixo. Sem isto, um teste que
        // morresse no meio deixaria um coletor órfão contando queries do teste seguinte.
        self::$coletores = [];

        DB::listen(function (QueryExecuted $evento): void {
            if (self::$coletores === []) {
                return;
            }

            foreach (array_keys(self::$coletores) as $indice) {
                self::$coletores[$indice]['queries']++;
                self::$coletores[$indice]['msSql'] += $evento->time;
                self::$coletores[$indice]['sqls'][] = $evento->sql;
            }
        });
    }

    private static function tamanhoDoCorpo(mixed $resultado): int
    {
        // TestResponse (dos testes) embrulha a Response real numa propriedade.
        if (is_object($resultado) && property_exists($resultado, 'baseResponse')) {
            $resultado = $resultado->baseResponse;
        }

        if (! $resultado instanceof Response) {
            return 0;
        }

        $conteudo = $resultado->getContent();

        return $conteudo === false ? 0 : strlen($conteudo);
    }
}
