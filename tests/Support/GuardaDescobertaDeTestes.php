<?php

namespace Tests\Support;

use PHPUnit\Event\TestSuite\Loaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Aborta a rodada se o PHPUnit não carregou todos os arquivos de teste do disco.
 *
 * Por que existe (2026-11-04): no Docker Desktop, o bind-mount Windows → container
 * devolve só parte das entradas quando o diretório é REBOBINADO (`rewinddir`), e o
 * `DirectoryIterator` do PHP, que o PHPUnit usa para varrer `tests/`, rebobina antes de
 * iterar. Resultado: o `php artisan test` rodava 444 dos 1.389 testes, sem erro nenhum,
 * e três testes ficaram quebrados sem ninguém ver. `scandir()` e `find` não rebobinam e
 * enxergam tudo, por isso esta trava conta o disco com `scandir()`.
 *
 * Só confere a rodada COMPLETA: com arquivo/pasta na linha de comando a suíte é
 * parcial de propósito. E só compara as pastas de que o PHPUnit carregou ALGUM arquivo,
 * para `--testsuite Unit` não ser confundido com defeito.
 *
 * Para rodar a suíte inteira nesta máquina: `composer test` (passa a lista de arquivos
 * explícita, montada pelo `find`).
 */
final class GuardaDescobertaDeTestes implements Extension
{
    private const PASTAS = ['tests/Unit', 'tests/Feature'];

    private const SUFIXO = 'Test.php';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if ($configuration->hasCliArguments()) {
            return;
        }

        $raiz = dirname(__DIR__, 2);

        $facade->registerSubscriber(new class($raiz) implements LoadedSubscriber
        {
            public function __construct(private readonly string $raiz) {}

            public function notify(Loaded $event): void
            {
                $carregados = [];
                foreach ($event->testSuite()->tests() as $teste) {
                    $carregados[realpath($teste->file()) ?: $teste->file()] = true;
                }

                $faltando = [];
                foreach (GuardaDescobertaDeTestes::pastas() as $pasta) {
                    $absoluta = $this->raiz.'/'.$pasta;
                    $noDisco = GuardaDescobertaDeTestes::arquivosDeTeste($absoluta);

                    $carregadosDaPasta = array_filter(
                        array_keys($carregados),
                        fn (string $arquivo) => str_starts_with($arquivo, (realpath($absoluta) ?: $absoluta).'/'),
                    );

                    if ($carregadosDaPasta === []) {
                        continue;
                    }

                    foreach ($noDisco as $arquivo) {
                        if (! isset($carregados[$arquivo])) {
                            $faltando[] = substr($arquivo, strlen($this->raiz) + 1);
                        }
                    }
                }

                if ($faltando === []) {
                    return;
                }

                fwrite(STDERR, sprintf(
                    "\n  SUÍTE INCOMPLETA: o PHPUnit não carregou %d arquivo(s) de teste que existem no disco.\n"
                    ."  É o defeito do bind-mount do Docker Desktop (ver tests/Support/GuardaDescobertaDeTestes.php).\n"
                    ."  Rode a suíte com:  composer test\n\n  Exemplos: %s\n\n",
                    count($faltando),
                    implode(', ', array_slice($faltando, 0, 5)),
                ));

                exit(2);
            }
        });
    }

    /** @return list<string> */
    public static function pastas(): array
    {
        return self::PASTAS;
    }

    /**
     * Arquivos `*Test.php` da pasta, recursivo, com `scandir()` (não rebobina o
     * diretório, então enxerga tudo no bind-mount).
     *
     * @return list<string>
     */
    public static function arquivosDeTeste(string $pasta): array
    {
        $achados = [];
        $entradas = @scandir($pasta) ?: [];

        foreach ($entradas as $entrada) {
            if ($entrada === '.' || $entrada === '..' || $entrada[0] === '.') {
                continue;
            }

            $caminho = $pasta.'/'.$entrada;

            if (is_dir($caminho)) {
                array_push($achados, ...self::arquivosDeTeste($caminho));
            } elseif (str_ends_with($entrada, self::SUFIXO)) {
                $achados[] = realpath($caminho) ?: $caminho;
            }
        }

        return $achados;
    }
}
