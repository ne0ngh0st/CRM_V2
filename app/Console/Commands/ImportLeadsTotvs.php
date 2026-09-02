<?php

namespace App\Console\Commands;

use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa a base de prospecção (base_marco) direto do arquivo.
 *
 * Difere do `legado:import-leads` em três pontos, e todos são correção de defeito, não
 * preferência:
 *
 * 1. NÃO APAGA E REINSERE. O comando do legado faz
 *    `delete where origem='sistema'` seguido de insert, o que troca o `id` de TODOS os
 *    leads a cada rodada. Como `observacoes.lead_id` e `agendamentos_ligacoes.lead_id`
 *    são ON DELETE SET NULL, cada importação desgarrava as observações dos seus leads em
 *    silêncio — hoje são 575 apontando para lead. Aqui o lead é adotado pelo CNPJ e o id
 *    é preservado.
 *
 * 2. DEDUPLICA POR CNPJ. A base traz 20.568 linhas marcadas como prospect para apenas
 *    17.154 CNPJs distintos: 3.414 empresas aparecem duas vezes, quase sempre com uma
 *    das cópias mais pobre (razão social em branco). Conferido: das 3.414, só ~62 diferem
 *    em alguma coluna que importa. O comando fica com a linha mais completa. O import
 *    antigo trazia as duas, e o vendedor via a mesma empresa duplicada na tela.
 *
 * 3. NÃO SOBRESCREVE O `status`. Lead que o vendedor marcou como convertido ou excluído
 *    voltava para "ativo" a cada importação, porque o comando antigo recriava tudo com
 *    status fixo. Aqui o status só é definido no cadastro novo.
 *
 * ⚠️ Mantém o filtro `MARCAÇÃO PROSPECT = 'SAI PROSPECT'` do comando antigo. Sem ele
 * entram 2.002 linhas marcadas "OK", que não são prospect e inundariam a tela.
 *
 * ⚠️ Lead que SUMIU da base não é apagado, de propósito. Apagar anularia as observações
 * que apontam para ele (SET NULL) e o histórico do vendedor sumiria sem deixar rastro.
 * O comando conta e informa quantos são; o que fazer com eles é decisão de negócio.
 *
 * ⚠️ `origem = manual` e `origem = wordpress` nunca são tocados: um é cadastro do
 * vendedor pela tela, o outro vem do formulário do site.
 */
class ImportLeadsTotvs extends Command
{
    private const MARCACAO_PROSPECT = 'SAI PROSPECT';

    protected $signature = 'totvs:import-leads
        {--chunk=1000 : tamanho do lote}
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa a base de prospecção do arquivo, deduplicando por CNPJ e preservando os ids';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $leitor = Relatorios::abrir('leads');
        $leitor->exigirColunas([
            'cnpj', 'RAZAO SOCIAL', 'NOME FANTASIA', 'nome final', 'E-mail',
            'Telefone Principal (FINAL)', 'endereçoCNPJJA', 'CIDADE (arrumada)', 'CIDADE',
            'UF', 'Codigo Vendedor', 'projeção R$ (mês)', 'MARCAÇÃO PROSPECT',
        ]);

        [$porCnpj, $lidas, $foraDoFiltro, $semCnpj, $semNome] = $this->lerBase($leitor);

        $this->line(sprintf(
            'base_marco: %s linhas, %s marcadas "%s", %s CNPJs distintos.',
            number_format($lidas, 0, ',', '.'),
            number_format($lidas - $foraDoFiltro, 0, ',', '.'),
            self::MARCACAO_PROSPECT,
            number_format(count($porCnpj), 0, ',', '.')
        ));

        $existentes = DB::table('leads')->where('origem', 'sistema')
            ->select('id', 'cnpj')->orderBy('id')->cursor()
            ->reduce(function (array $mapa, $l) {
                $digitos = preg_replace('/\D/', '', (string) $l->cnpj);
                // Primeiro id vence: os 13 CNPJs duplicados que o import antigo criou
                // ficam apontando para a linha mais antiga, que é a que as observações
                // provavelmente referenciam.
                $mapa[$digitos] ??= $l->id;

                return $mapa;
            }, []);

        $novos = array_diff_key($porCnpj, $existentes);
        $adotados = array_intersect_key($porCnpj, $existentes);
        $sumiram = count(array_diff_key($existentes, $porCnpj));

        $this->line('  já no CRM (atualiza, mantendo o id): '.number_format(count($adotados), 0, ',', '.'));
        $this->line('  cadastros novos: '.number_format(count($novos), 0, ',', '.'));

        if ($sumiram > 0) {
            $this->warn('  sumiram da base e NÃO serão apagados: '.number_format($sumiram, 0, ',', '.'));
            $this->line('    → apagar anularia as observações que apontam para eles.');
        }

        if ($dryRun) {
            $this->info('[dry-run] nada foi escrito.');
        } else {
            DB::transaction(function () use ($novos, $adotados, $existentes, $chunk) {
                $this->inserir($novos, $chunk);
                $this->atualizar($adotados, $existentes);
            });

            $this->info('Leads gravados: '.number_format(count($porCnpj), 0, ',', '.'));
        }

        if ($semCnpj > 0) {
            $this->warn("Ignorados (sem CNPJ): {$semCnpj}");
        }

        if ($semNome > 0) {
            $this->warn("Ignorados (sem razão social nem nome): {$semNome}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: int, 3: int, 4: int}
     */
    private function lerBase(\App\Services\Totvs\LeitorRelatorio $leitor): array
    {
        $porCnpj = [];
        $preenchimento = [];
        $lidas = $foraDoFiltro = $semCnpj = $semNome = 0;

        foreach ($leitor->linhas() as $linha) {
            $lidas++;

            if (strtoupper(trim($linha['MARCAÇÃO PROSPECT'])) !== self::MARCACAO_PROSPECT) {
                $foraDoFiltro++;

                continue;
            }

            $digitos = preg_replace('/\D/', '', $linha['cnpj']);
            if ($digitos === '') {
                $semCnpj++;

                continue;
            }

            $razaoSocial = Normalizador::valorOuNull($linha['RAZAO SOCIAL'])
                ?? Normalizador::valorOuNull($linha['nome final']);

            if ($razaoSocial === null) {
                $semNome++;

                continue;
            }

            $registro = [
                'cod_vendedor' => Normalizador::valorOuNull($linha['Codigo Vendedor']),
                'nome' => Normalizador::valorOuNull($linha['nome final']) ?? $razaoSocial,
                'razao_social' => $razaoSocial,
                'nome_fantasia' => Normalizador::valorOuNull($linha['NOME FANTASIA']),
                'cnpj' => Normalizador::documento($linha['cnpj']),
                'email' => Normalizador::email($linha['E-mail']),
                'telefone' => Normalizador::valorOuNull($linha['Telefone Principal (FINAL)']),
                'endereco' => Normalizador::valorOuNull($linha['endereçoCNPJJA']),
                'cidade' => Normalizador::valorOuNull($linha['CIDADE (arrumada)'])
                    ?? Normalizador::valorOuNull($linha['CIDADE']),
                'estado' => Normalizador::valorOuNull($linha['UF']),
                // Sem de-para de segmento para lead na fonte — não inventar (decisão do Tony).
                'segmento' => null,
                'valor_estimado' => $this->valorPositivoOuNull($linha['projeção R$ (mês)']),
            ];

            // Duas linhas do mesmo CNPJ: fica a mais completa. Quase sempre a diferença
            // é uma das cópias vir com a razão social em branco.
            $preenchidos = count(array_filter($registro, fn ($v) => $v !== null && $v !== ''));

            if (! isset($porCnpj[$digitos]) || $preenchidos > $preenchimento[$digitos]) {
                $porCnpj[$digitos] = $registro;
                $preenchimento[$digitos] = $preenchidos;
            }
        }

        return [$porCnpj, $lidas, $foraDoFiltro, $semCnpj, $semNome];
    }

    /**
     * @param  array<string, array<string, mixed>>  $novos
     */
    private function inserir(array $novos, int $chunk): void
    {
        $agora = now();
        $lote = [];

        foreach ($novos as $registro) {
            $lote[] = $registro + [
                'origem' => 'sistema',
                'user_id' => null,
                // Só no cadastro NOVO: em lead que já existe, o status é do CRM.
                'status' => 'ativo',
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                DB::table('leads')->insert($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            DB::table('leads')->insert($lote);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $adotados
     * @param  array<string, int>  $existentes
     */
    private function atualizar(array $adotados, array $existentes): void
    {
        $agora = now();

        foreach ($adotados as $digitos => $registro) {
            DB::table('leads')->where('id', $existentes[$digitos])
                ->update($registro + ['updated_at' => $agora]);
        }
    }

    private function valorPositivoOuNull(mixed $valor): ?float
    {
        $numero = Normalizador::numero($valor);

        return $numero > 0 ? $numero : null;
    }
}
