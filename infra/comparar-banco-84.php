<?php
/*
 * Compara azul (8.0) x verde (8.4) pelas páginas reais, rodando pelo kernel HTTP.
 * Uso, de /var/www/crm:  php /tmp/comparar-84.php <host_azul> <host_verde> [rodadas]
 * Só leitura: cache e sessão em memória; o verde é read-only de qualquer jeito.
 */

use Illuminate\Support\Facades\DB;

require '/var/www/crm/vendor/autoload.php';
$app = require '/var/www/crm/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$_, $azul, $verde] = $argv;
$rodadas = (int) ($argv[3] ?? 3);
$hosts = ['azul' => $azul, 'verde' => $verde];

config([
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
]);
app('cache')->setDefaultDriver('array');

$paginas = [
    '/dashboard',
    '/carteira',
    '/carteira?status=inativo',
    '/carteira?aderencia=fora',
    '/carteira?ordenar=ultimo_contato_desc',
    '/carteira?receita=irregular',
    '/metas',
    '/visao-gestor',
    '/leads',
    '/pedidos-abertos',
    '/pedidos-emitidos',
    '/orcamentos',
    '/equipe',
];

$usuario = App\Models\User::where('email', 'antonio.barbosa@autopel.com')->firstOrFail();

$tempos = [];   // [pagina][host][] = ms de banco
$lentas = [];   // [host][sql] = min ms entre as rodadas
$erros = [];

for ($r = 1; $r <= $rodadas; $r++) {
    foreach ($paginas as $pagina) {
        // Intercala: a ordem alterna a cada rodada para não favorecer ninguém.
        $ordem = $r % 2 ? ['azul', 'verde'] : ['verde', 'azul'];
        foreach ($ordem as $rotulo) {
            config(['database.connections.mysql.host' => $hosts[$rotulo]]);
            DB::purge('mysql');
            app('cache')->store('array')->flush();

            $total = 0.0;
            DB::listen(function ($q) use (&$total, &$lentas, $rotulo) {
                $total += $q->time;
                $sql = preg_replace('/\s+/', ' ', $q->sql);
                $lentas[$rotulo][$sql] = min($lentas[$rotulo][$sql] ?? INF, $q->time);
            });

            app('auth')->guard('web')->setUser($usuario);
            $req = Illuminate\Http\Request::create($pagina, 'GET');
            $t0 = microtime(true);
            try {
                $resp = $kernel->handle($req);
                $status = $resp->getStatusCode();
            } catch (Throwable $e) {
                $status = 'EXC '.$e->getMessage();
            }
            $parede = (microtime(true) - $t0) * 1000;

            if ($status !== 200) {
                $erros[] = "$rotulo $pagina -> ".(is_string($status) ? $status : $status.' '.substr((string) ($resp->exception?->getMessage() ?? ''), 0, 200));
            }
            $tempos[$pagina][$rotulo][] = [round($total, 1), round($parede, 1)];

            // Remove o listener desta rodada.
            app('events')->forget(Illuminate\Database\Events\QueryExecuted::class);
        }
    }
}

printf("%-40s %16s %16s %8s\n", 'página (ms de banco: mínimo das rodadas)', 'azul 8.0', 'verde 8.4', 'verde/azul');
foreach ($tempos as $pagina => $porHost) {
    $a = min(array_column($porHost['azul'], 0));
    $v = min(array_column($porHost['verde'], 0));
    printf("%-40s %16.1f %16.1f %8s\n", $pagina, $a, $v, $a > 0 ? sprintf('%.2fx', $v / $a) : '-');
}

echo "\nConsultas que mais pioraram no verde (verde - azul > 30 ms):\n";
$piores = [];
foreach ($lentas['verde'] ?? [] as $sql => $msV) {
    $msA = $lentas['azul'][$sql] ?? null;
    if ($msA !== null && $msV - $msA > 30) {
        $piores[] = [$msV, $msA, $sql];
    }
}
usort($piores, fn ($x, $y) => $y[0] <=> $x[0]);
foreach (array_slice($piores, 0, 15) as [$v, $a, $sql]) {
    printf("  verde %7.1f | azul %7.1f | %s\n", $v, $a, substr($sql, 0, 400));
}
if ($piores === []) {
    echo "  nenhuma\n";
}

echo "\nErros:\n".($erros ? implode("\n", array_unique($erros)) : "  nenhum")."\n";
