<?php

namespace App\Console\Commands;

use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\AbasDaPlanilha;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use App\Services\VisaoDiretor\SugestaoDeVinculo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Carga INICIAL da Visão Diretor → Maiores por Segmento, a partir da planilha que a
 * diretoria mantinha ("MAIORES POR SEGMENTO - CRM.xlsx"). Depois dela, tudo se edita na
 * tela e a planilha morre (decisão do Tony, 18/09/2026).
 *
 * ⚠️ ABAS PELO NOME, COLUNAS PELO CABEÇALHO — nunca por posição. As abas têm ordens
 * diferentes: DROGARIAS traz uma coluna A extra (o nome original) antes do NOME, e só REDE
 * LOJAS tem SITE. Mapeamento posicional leria o nome errado em silêncio, mesma lição do
 * `faturamento_xlsx_para_csv.py` (31/08).
 *
 * ⚠️ ATENDIMENTO e STATUS da planilha NÃO são importados: passam a ser derivados do CRM.
 * Eles servem só para o relatório de DIVERGÊNCIAS do fim — a lista de contas cujo vínculo
 * sugerido provavelmente está errado ou faltando.
 *
 * ⚠️ A sugestão de vínculo mora em `SugestaoDeVinculo` (prefixo com fronteira de
 * token, depois token distintivo + maioria no segmento). Este comando só aplica o
 * resultado. Casar pelo começo do compacto sozinho pegava 47 de 383 contas da
 * planilha real; a regra solta gerava centenas de FPs (prefeitura no lugar da
 * Drogaria São Paulo). Combinada: 99 contas / 173 grupos.
 *
 * ⚠️ Idempotente e conservador: conta que já existe não tem campo preenchido sobrescrito,
 * e conta com qualquer vínculo `manual` não tem os vínculos tocados. Rodar de novo depois
 * de editar na tela não desfaz a edição.
 */
class ImportarMaioresSegmento extends Command
{
    protected $signature = 'diretor:importar-maiores-segmento
        {arquivo : caminho do .xlsx}
        {--dry-run : faz tudo numa transação e desfaz no fim (mostra o relatório sem gravar)}';

    protected $description = 'Carga inicial das contas estratégicas (Visão Diretor) a partir da planilha';

    public function handle(ClientesDaConta $clientesDaConta, MaioresPorSegmentoResolver $resolver, SugestaoDeVinculo $sugestao): int
    {
        $arquivo = (string) $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $this->info('Banco alvo: '.DB::connection()->getDatabaseName().($this->option('dry-run') ? ' (dry-run: nada será gravado)' : ''));

        $planilha = IOFactory::createReaderForFile($arquivo)->setReadDataOnly(true)->load($arquivo);
        $abas = collect($planilha->getWorksheetIterator())
            ->keyBy(fn (Worksheet $w) => $this->normalizar($w->getTitle()));

        $catalogo = $sugestao->catalogo();
        $relatorio = ['contas' => 0, 'novas' => 0, 'sugestoes' => 0, 'semSugestao' => [], 'ambiguas' => [], 'excel' => []];

        DB::beginTransaction();

        try {
            foreach (AbasDaPlanilha::ABAS as $nomeAba => $codigoSegmento) {
                $aba = $abas->get($nomeAba);

                if (! $aba) {
                    $this->warn("Aba \"{$nomeAba}\" não encontrada — pulada.");

                    continue;
                }

                $segmento = Segmento::where('codigo', $codigoSegmento)->first();

                if (! $segmento) {
                    $this->warn("Segmento {$codigoSegmento} não existe na tabela segmentos — aba {$nomeAba} pulada.");

                    continue;
                }

                [$titulo, $linhas] = $this->lerAba($aba);
                $this->definirEspecialista($segmento, $titulo);

                foreach ($linhas as $ordem => $linha) {
                    $relatorio['contas']++;
                    $conta = ContaEstrategica::firstOrNew(['segmento_id' => $segmento->id, 'nome' => $linha['nome']]);
                    $relatorio['novas'] += $conta->exists ? 0 : 1;

                    // Só preenche o que está vazio: re-rodar não desfaz edição feita na tela.
                    foreach (['uf' => 'uf', 'filiais_mercado' => 'filiais', 'site' => 'site', 'observacao' => 'obs'] as $coluna => $campo) {
                        if (blank($conta->{$coluna}) && filled($linha[$campo])) {
                            $conta->{$coluna} = $linha[$campo];
                        }
                    }

                    if (! $conta->exists) {
                        $conta->ordem = $ordem + 1;
                    }

                    $conta->save();

                    $relatorio['excel'][$conta->id] = $linha['status'];

                    if ($conta->vinculos()->where('origem', ContaEstrategicaVinculo::ORIGEM_MANUAL)->exists()) {
                        continue;
                    }

                    $resultado = $sugestao->sugerir($linha['nome'], $segmento->codigo, $catalogo);
                    $sugeridos = $resultado['grupos'];

                    if ($resultado['ambiguo']) {
                        $relatorio['ambiguas'][] = "{$segmento->nome} · {$linha['nome']}";
                    } elseif ($sugeridos->isEmpty()) {
                        $relatorio['semSugestao'][] = "{$segmento->nome} · {$linha['nome']}";
                    }

                    $relatorio['sugestoes'] += $sugeridos->count();

                    $clientesDaConta->sincronizarVinculos($conta, $sugeridos->map(fn (array $g) => [
                        'tipo' => ContaEstrategicaVinculo::TIPO_GRUPO,
                        'codigo' => $g['codigo'],
                        'origem' => ContaEstrategicaVinculo::ORIGEM_SUGESTAO,
                    ])->all());
                }
            }

            $this->relatar($relatorio, $resolver);

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Dry-run: tudo desfeito.');
            } else {
                DB::commit();
                $this->info('Gravado.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<array{nome: string, uf: ?string, filiais: ?int, site: ?string, obs: ?string, status: ?string}>}
     */
    private function lerAba(Worksheet $aba): array
    {
        $titulo = '';
        $colunas = [];

        // Título na linha 1 (A1 ou B1, varia), cabeçalho na linha 2.
        foreach ($aba->getRowIterator(1, 2) as $linha) {
            foreach ($linha->getCellIterator() as $celula) {
                $valor = trim((string) $celula->getValue());

                if ($valor === '') {
                    continue;
                }

                if ($linha->getRowIndex() === 1 && $titulo === '') {
                    $titulo = $valor;
                } elseif ($linha->getRowIndex() === 2) {
                    $chave = $this->normalizar($valor);
                    // Primeira ocorrência vence: DROGARIAS repete cabeçalhos mais à direita.
                    $colunas[$chave] ??= $celula->getColumn();
                }
            }
        }

        if (! isset($colunas['NOME'])) {
            $this->warn("Aba \"{$aba->getTitle()}\" sem coluna NOME no cabeçalho — pulada.");

            return [$titulo, []];
        }

        $ler = fn (string $chave, int $linha) => isset($colunas[$chave])
            ? trim((string) $aba->getCell($colunas[$chave].$linha)->getValue())
            : '';

        $linhas = [];

        for ($i = 3; $i <= $aba->getHighestDataRow(); $i++) {
            $nome = preg_replace('/\s+/', ' ', $ler('NOME', $i));

            if ($nome === '') {
                continue;
            }

            $uf = mb_strtoupper($ler('UF', $i));
            $filiais = preg_replace('/\D/', '', $ler('FILIAIS', $i));

            $linhas[] = [
                'nome' => Str::limit($nome, 150, ''),
                'uf' => preg_match('/^[A-Z]{2}$/', $uf) ? $uf : null,
                'filiais' => $filiais !== '' ? (int) $filiais : null,
                'site' => $ler('SITE', $i) ?: null,
                'obs' => $this->observacaoUtil($ler('OBS', $i)),
                'status' => $this->normalizar($ler('STATUS', $i)) ?: null,
            ];
        }

        // Nome repetido na mesma aba viraria violação de unique; fica o primeiro.
        return [$titulo, collect($linhas)->unique(fn ($l) => mb_strtoupper($l['nome']))->values()->all()];
    }

    /** "OK" na coluna OBS não é observação — era o jeito da planilha dizer "nada a anotar". */
    private function observacaoUtil(string $obs): ?string
    {
        return in_array(mb_strtoupper($obs), ['', 'OK', '-'], true) ? null : $obs;
    }

    /**
     * "DROGARIAS - Inaya" → procura a Inaya entre os usuários ativos. Só grava se o
     * segmento ainda não tem especialista e se o nome casar com UM usuário só — dois
     * candidatos vão para o relatório em vez de virar um palpite.
     */
    private function definirEspecialista(Segmento $segmento, string $titulo): void
    {
        if ($segmento->especialista_user_id || ! str_contains($titulo, '-')) {
            return;
        }

        $nome = trim(Str::after($titulo, '-'));

        if ($nome === '') {
            return;
        }

        $candidatos = User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('display_name', 'like', "{$nome}%")->orWhere('name', 'like', "{$nome}%"))
            ->get(['id', 'name', 'display_name']);

        if ($candidatos->count() === 1) {
            $segmento->update(['especialista_user_id' => $candidatos->first()->id]);
            $this->line("Especialista de {$segmento->nome}: ".($candidatos->first()->display_name ?: $candidatos->first()->name));
        } else {
            $this->warn("Especialista de {$segmento->nome} (\"{$nome}\"): {$candidatos->count()} usuários casam — defina na tela.");
        }
    }

    private function relatar(array $r, MaioresPorSegmentoResolver $resolver): void
    {
        $this->newLine();
        $this->info("Contas lidas: {$r['contas']} ({$r['novas']} novas) · grupos sugeridos: {$r['sugestoes']}");

        if ($r['ambiguas']) {
            $this->warn('Ambíguas (casariam grupos demais, ficaram sem vínculo):');
            foreach ($r['ambiguas'] as $l) {
                $this->line("  - {$l}");
            }
        }

        $this->warn(count($r['semSugestao']).' conta(s) sem vínculo sugerido — vincular na tela:');
        foreach ($r['semSugestao'] as $l) {
            $this->line("  - {$l}");
        }

        /*
         * Divergência = a planilha dizia uma coisa e o CRM, com o vínculo sugerido, diz
         * outra. É a lista de revisão: quase sempre é vínculo faltando ou sobrando.
         */
        $derivadas = $resolver->linhas()->keyBy('id');
        $divergencias = [];

        foreach ($r['excel'] as $id => $statusExcel) {
            $linha = $derivadas->get($id);

            if (! $linha || $statusExcel === null) {
                continue;
            }

            $excel = match ($statusExcel) {
                'ATIVO' => 'ativo',
                'LEAD' => 'lead',
                'A TRABALHAR' => 'a trabalhar',
                default => null,
            };
            $crm = match ($linha['status']) {
                'inativando', 'inativo' => 'a trabalhar',
                default => $linha['status'],
            };

            if ($excel !== null && $excel !== $crm) {
                $divergencias[] = [$linha['segmento']['nome'], $linha['nome'], $statusExcel, $crm, $linha['lojas']];
            }
        }

        $this->newLine();
        $this->warn(count($divergencias).' divergência(s) planilha × CRM:');

        if ($divergencias) {
            $this->table(['Segmento', 'Conta', 'Planilha', 'CRM', 'Nossas lojas'], $divergencias);
        }
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper(Str::ascii($texto))));
    }

}
