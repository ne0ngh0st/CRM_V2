<?php

namespace App\Console\Commands;

use App\Models\PotencialPeso;
use App\Models\Segmento;
use App\Services\Potencial\FamiliaProduto;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Importa a matriz de peso segmento × família de um CSV.
 *
 * Existe para que a direção entregue os pesos sem depender de deploy: hoje todos são 1,00
 * (`PotencialPesoSeeder`) e o card avisa na tela que a priorização ainda não existe. O
 * legado guardava o equivalente num CSV versionado (`segmentos_2026_curva_abc.csv`).
 *
 * Formato, separado por `;` ou `,`, com cabeçalho opcional:
 *
 *     segmento;familia;peso
 *     SUPERMERCADISTA;bobina;3
 *     SUPERMERCADISTA;etiqueta;2,5
 *     POSTOS E CONVENIENCIAS;etiqueta;0
 *
 * O segmento pode vir pelo NOME ou pelo CÓDIGO — quem preenche a planilha pensa em nome.
 * Peso aceita vírgula decimal, que é o que o Excel em pt-BR produz.
 */
class ImportarPesosPotencial extends Command
{
    protected $signature = 'potencial:importar-pesos {arquivo : caminho do CSV} {--dry-run : só mostra o que faria}';

    protected $description = 'Importa a matriz de pesos (segmento × família) do Potencial da Carteira';

    public function handle(): int
    {
        $caminho = (string) $this->argument('arquivo');

        if (! is_readable($caminho)) {
            $this->error("Arquivo não encontrado ou sem permissão de leitura: {$caminho}");

            return self::FAILURE;
        }

        // Nome e código apontam para o mesmo id: quem preenche a planilha usa o nome,
        // mas o código também é aceito para quem exporta do TOTVS.
        $porNome = Segmento::query()->pluck('id', 'nome');
        $porCodigo = Segmento::query()->pluck('id', 'codigo');

        $linhas = $this->lerCsv($caminho);

        if ($linhas === []) {
            $this->error('CSV vazio.');

            return self::FAILURE;
        }

        $aplicar = [];
        $erros = [];

        foreach ($linhas as $numero => $linha) {
            [$segmentoBruto, $familiaBruta, $pesoBruto] = $linha;

            $segmentoId = $porNome[$segmentoBruto]
                ?? $porCodigo[$segmentoBruto]
                ?? $porNome[mb_strtoupper($segmentoBruto)]
                ?? null;

            if ($segmentoId === null) {
                $erros[] = "linha {$numero}: segmento desconhecido \"{$segmentoBruto}\"";

                continue;
            }

            try {
                $familia = FamiliaProduto::garantir(mb_strtolower(trim($familiaBruta)));
            } catch (InvalidArgumentException $e) {
                $erros[] = "linha {$numero}: {$e->getMessage()}";

                continue;
            }

            $peso = str_replace(',', '.', trim($pesoBruto));

            if (! is_numeric($peso) || (float) $peso < 0) {
                $erros[] = "linha {$numero}: peso inválido \"{$pesoBruto}\" (use número >= 0)";

                continue;
            }

            $aplicar[] = ['segmento_id' => $segmentoId, 'familia' => $familia, 'peso' => (float) $peso];
        }

        /*
         * ⚠️ Nada é gravado se QUALQUER linha estiver errada. Aplicar as boas e reclamar
         * das ruins deixaria a matriz meio velha e meio nova, sem ninguém saber qual metade
         * — e um peso errado não estoura em lugar nenhum, só muda um número na tela.
         */
        if ($erros !== []) {
            $this->error(count($erros).' problema(s) — nada foi gravado:');
            foreach ($erros as $erro) {
                $this->line('  · '.$erro);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(count($aplicar).' peso(s) seriam gravados. Nada foi alterado (--dry-run).');
            $this->table(['segmento_id', 'familia', 'peso'], $aplicar);

            return self::SUCCESS;
        }

        foreach ($aplicar as $item) {
            PotencialPeso::query()->updateOrCreate(
                ['segmento_id' => $item['segmento_id'], 'familia' => $item['familia']],
                ['peso' => $item['peso']],
            );
        }

        $this->info(count($aplicar).' peso(s) gravados.');
        $this->line('O Painel usa cache de 30 min por bloco — rode `php artisan cache:clear` para ver na hora.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function lerCsv(string $caminho): array
    {
        $linhas = [];
        $handle = fopen($caminho, 'r');

        if ($handle === false) {
            return [];
        }

        $numero = 0;

        while (($bruto = fgets($handle)) !== false) {
            $numero++;
            $bruto = trim($bruto, " \t\n\r\0\x0B\xEF\xBB\xBF");

            if ($bruto === '') {
                continue;
            }

            $campos = array_map('trim', preg_split('/[;,]/', $bruto) ?: []);

            if (count($campos) < 3) {
                continue;
            }

            // Cabeçalho: reconhecido pelo conteúdo, não pela posição — planilha
            // reexportada às vezes perde a primeira linha.
            if (mb_strtolower($campos[0]) === 'segmento') {
                continue;
            }

            $linhas[$numero] = [$campos[0], $campos[1], $campos[2]];
        }

        fclose($handle);

        return $linhas;
    }
}
