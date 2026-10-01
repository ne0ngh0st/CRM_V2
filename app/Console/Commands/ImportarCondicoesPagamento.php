<?php

namespace App\Console\Commands;

use App\Services\Orcamento\CondicoesPagamento;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Recarrega as condições de pagamento a partir do relatório da SE4 do Protheus (o mesmo
 * "Listagem do Browse" em xlsx que veio em 2026-10-01).
 *
 * Mapeia por NOME de coluna (Tipo / Descricao / Codigo), nunca por posição, e ignora o
 * cabeçalho que o relatório repete a cada página. Condição que saiu da lista é
 * desativada, não apagada.
 *
 * ⚠️ Depois de rodar, atualize também database/data/condicoes_pagamento.json (`--json`),
 * senão um banco novo nasce com a lista velha.
 */
class ImportarCondicoesPagamento extends Command
{
    protected $signature = 'condicoes-pagamento:importar {arquivo : xlsx da SE4} {--json : reescreve database/data/condicoes_pagamento.json} {--dry-run}';

    protected $description = 'Recarrega as condições de pagamento do Protheus (SE4)';

    public function handle(): int
    {
        $linhas = IOFactory::load($this->argument('arquivo'))->getActiveSheet()->toArray(null, true, true, false);

        $colunas = null;
        $condicoes = [];

        foreach ($linhas as $linha) {
            $celulas = array_map(fn ($v) => trim((string) $v), $linha);

            if (in_array('Codigo', $celulas, true) && in_array('Descricao', $celulas, true)) {
                $colunas = array_flip($celulas);   // cabeçalho (repetido a cada página)
                continue;
            }

            if ($colunas === null || ($celulas[$colunas['Codigo']] ?? '') === '') {
                continue;
            }

            $codigo = $celulas[$colunas['Codigo']];
            $condicoes[$codigo] = [
                'codigo' => $codigo,
                'descricao' => preg_replace('/\s+/', ' ', $celulas[$colunas['Descricao']]),
                'tipo' => isset($colunas['Tipo']) ? $celulas[$colunas['Tipo']] : null,
            ];
        }

        if ($condicoes === []) {
            $this->error('Nenhuma condição encontrada — o arquivo tem as colunas Codigo e Descricao?');

            return self::FAILURE;
        }

        ksort($condicoes);
        $this->info(count($condicoes).' condições no arquivo.');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $r = CondicoesPagamento::sincronizar($condicoes);
        $this->info("Gravadas: {$r['gravadas']}. Desativadas (saíram da lista): {$r['desativadas']}.");

        if ($this->option('json')) {
            file_put_contents(base_path(CondicoesPagamento::ARQUIVO), json_encode([
                'fonte' => 'SE4 do Protheus — '.basename($this->argument('arquivo')).' (importado em '.now()->format('d/m/Y').')',
                'condicoes' => array_values($condicoes),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->info('database/data/condicoes_pagamento.json atualizado.');
        }

        return self::SUCCESS;
    }
}
