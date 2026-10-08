<?php

namespace App\Console\Commands;

use App\Services\Leads\SegmentoDosLeads;
use App\Services\Receita\PorteEmpresa;
use App\Services\Receita\SituacaoCadastral;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use App\Services\VisaoDiretor\ContaDoLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Carga mensal da situação cadastral a partir da base aberta de CNPJ da Receita.
 *
 * Existe porque as APIs gratuitas do cartão CNPJ não servem para consulta em massa
 * (CNPJá: 5/min), e a regra "lead com CNPJ não ativo não entra no CRM" precisa da
 * situação dos ~20 mil CNPJs da base de prospecção de uma vez.
 *
 * A base tem ~65 milhões de estabelecimentos em 10 zips (~5 GB). O comando NÃO grava a
 * base: grava só a situação dos CNPJs que interessam ao CRM (clientes, leads e a base de
 * prospecção que ainda vai ser importada). Cada zip é baixado, varrido em streaming e
 * apagado antes do próximo — o disco precisa aguentar UM arquivo (~2,3 GB no maior).
 *
 * ⚠️ CNPJ de interesse que não aparece em NENHUM dos 10 arquivos vira INEXISTENTE — e
 * por isso a marcação só acontece se a varredura terminou inteira. Uma carga que caiu
 * no meio do arquivo 7 não pode concluir que tudo que estava nos 8, 9 e 10 não existe.
 *
 * Depois varre os 10 zips de EMPRESAS (~1,4 GB no total, 563 MB no maior) para o capital
 * social e o porte, que na Receita são da empresa — os 8 primeiros dígitos do CNPJ. A raiz
 * é calculada em memória e o valor é gravado em cada CNPJ de 14 dígitos: nenhuma coluna de
 * raiz é armazenada (Regra de ouro nº 3).
 *
 * Do mesmo arquivo de Estabelecimentos sai o CNAE principal, que classifica o segmento
 * dos leads da prospecção (`SegmentoDosLeads`).
 *
 * Termina chamando `sincronizarLeads()`: lead que já estava no CRM e cuja situação
 * passou a ser conhecida como não ativa sai na hora, sem esperar o próximo import. Depois,
 * `SegmentoDosLeads::classificar()`: lead sem segmento ganha o do CNAE.
 */
class ImportarSituacoesReceita extends Command
{
    protected $signature = 'receita:importar-situacoes
        {--referencia= : mês da base (AAAA-MM); padrão = o mais recente publicado}
        {--arquivo=* : zips de Estabelecimentos já baixados (pula o download; uso em teste e reprocessamento)}
        {--empresas=* : zips de Empresas já baixados (só junto de --arquivo; sem eles, capital e porte não mudam)}
        {--forcar : recarrega mesmo se este mês já foi carregado}
        {--dry-run : varre e conta, sem gravar nada}';

    protected $description = 'Carrega a situação cadastral (Receita) dos CNPJs de leads e clientes';

    public function handle(SituacaoCadastral $situacoes, SegmentoDosLeads $segmentos, ContaDoLead $contaDoLead): int
    {
        $locais = (array) $this->option('arquivo');
        $referencia = $this->option('referencia') ?: ($locais === [] ? $this->referenciaMaisRecente() : null);

        if ($referencia !== null && ! $this->option('forcar') && $locais === [] && $this->jaCarregada($referencia)) {
            $this->info("Base {$referencia} já carregada. Nada a fazer (--forcar recarrega).");

            return self::SUCCESS;
        }

        $interesse = $this->cnpjsDeInteresse();
        $this->line('CNPJs de interesse: '.number_format(count($interesse), 0, ',', '.'));

        $achados = [];
        $arquivos = $locais !== [] ? $locais : range(0, (int) config('receita.base.arquivos') - 1);

        foreach ($arquivos as $arquivo) {
            $inicio = microtime(true);
            $caminho = is_int($arquivo) ? $this->baixar($referencia, $arquivo) : $arquivo;

            try {
                $linhas = $this->varrer($caminho, $interesse, $achados);
            } finally {
                if (is_int($arquivo)) {
                    @unlink($caminho);
                }
            }

            $this->line(sprintf('  %s: %s linhas, %s achados até aqui (%.0f s)',
                basename($caminho), number_format($linhas, 0, ',', '.'),
                number_format(count($achados), 0, ',', '.'), microtime(true) - $inicio));
        }

        /*
         * Capital e porte. Com --arquivo e sem --empresas (teste, reprocessamento) a etapa
         * não roda e as colunas ficam como estão — gravar nulo ali seria apagar.
         */
        $empresas = null;
        $locaisEmpresas = (array) $this->option('empresas');

        if ($locais === [] || $locaisEmpresas !== []) {
            $empresas = $this->capitalEPorte($achados, $locaisEmpresas !== [] ? $locaisEmpresas : null, $referencia);
        }

        // Só chega aqui com a varredura inteira: o que não apareceu, não existe.
        $inexistentes = array_diff_key($interesse, $achados);
        $contagem = array_count_values(array_column($achados, 0));
        $contagem[SituacaoCadastral::INEXISTENTE] = count($inexistentes);
        arsort($contagem);

        foreach ($contagem as $situacao => $total) {
            $this->line(sprintf('  %-12s %s', $situacao, number_format($total, 0, ',', '.')));
        }

        if ($this->option('dry-run')) {
            $this->info('[dry-run] nada foi gravado.');

            return self::SUCCESS;
        }

        $this->gravar($achados, $inexistentes, $referencia, $empresas);

        $leads = $situacoes->sincronizarLeads();
        $this->info('Leads da base de prospecção tirados do CRM (CNPJ não ativo): '.array_sum($leads['excluidos']));
        foreach ($leads['excluidos'] as $situacao => $total) {
            $this->line("  {$situacao}: {$total}");
        }
        $this->info('Leads que voltaram (CNPJ regularizado na Receita): '.$leads['reativados']);

        $classificacao = $segmentos->classificar();
        $this->info('Leads que ganharam segmento pelo CNAE: '.array_sum($classificacao['classificados']));
        foreach ($classificacao['classificados'] as $segmento => $total) {
            $this->line("  {$segmento}: {$total}");
        }

        $contas = $contaDoLead->sugerirParaLeadsSemConta();
        $this->info("Leads sugeridos para uma rede da Maiores por Segmento: {$contas['sugeridos']}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function cnpjsDeInteresse(): array
    {
        $interesse = [];
        $adicionar = function (?string $valor) use (&$interesse) {
            $digitos = preg_replace('/\D/', '', (string) $valor);
            if (strlen($digitos) === 14) {
                $interesse[$digitos] = true;
            }
        };

        DB::table('clientes')->whereNotNull('cnpj')->orderBy('id')->select('cnpj')
            ->cursor()->each(fn ($c) => $adicionar($c->cnpj));
        DB::table('leads')->whereNotNull('cnpj')->orderBy('id')->select('cnpj')
            ->cursor()->each(fn ($l) => $adicionar($l->cnpj));

        // A base de prospecção que AINDA vai ser importada: sem ela, o lead novo chegaria
        // sem situação conhecida e o import teria que segurá-lo até a próxima carga.
        // Todos os CSVs da pasta Leads/ (um por segmento), não só o mais recente.
        foreach (Relatorios::todos('leads') as $arquivo) {
            foreach (Relatorios::abrirArquivo($arquivo, 'leads')->linhas() as $linha) {
                $adicionar(Normalizador::digitosCnpj($linha['cnpj'] ?? null));
            }
        }

        return $interesse;
    }

    /**
     * Varre um zip de Estabelecimentos. Layout (sem cabeçalho, `;`, aspas, latin1):
     * 0 cnpj_basico · 1 cnpj_ordem · 2 cnpj_dv · 3 matriz/filial · 4 nome fantasia ·
     * 5 situação cadastral · 6 data da situação · 7 motivo · 8 cidade no exterior · 9 país ·
     * 10 início de atividade · 11 CNAE principal ("4711302") · …
     *
     * São ~6,5 milhões de linhas por arquivo e o CRM quer ~0,2% delas: o CNPJ sai por
     * posição fixa e só a linha que interessa paga o `str_getcsv`.
     *
     * @param  array<string, true>  $interesse
     * @param  array<string, array{0: string, 1: ?string, 2: ?string}>  $achados
     */
    private function varrer(string $caminho, array $interesse, array &$achados): int
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true || $zip->numFiles < 1) {
            throw new RuntimeException("Não consegui abrir o zip da Receita: {$caminho}");
        }

        $stream = $zip->getStream($zip->getNameIndex(0));
        if ($stream === false) {
            throw new RuntimeException("Zip da Receita sem conteúdo legível: {$caminho}");
        }

        $linhas = 0;

        try {
            while (($linha = fgets($stream)) !== false) {
                $linhas++;

                // "12345678";"0001";"12";…  → posições fixas.
                $cnpj = $linha[0] === '"' && ($linha[9] ?? '') === '"'
                    ? substr($linha, 1, 8).substr($linha, 12, 4).substr($linha, 19, 2)
                    : $this->cnpjPorCsv($linha);

                if (! isset($interesse[$cnpj])) {
                    continue;
                }

                $campos = str_getcsv($linha, ';', '"', '');
                $achados[$cnpj] = [
                    SituacaoCadastral::deCodigo($campos[5] ?? ''),
                    SituacaoCadastral::data($campos[6] ?? null),
                    self::cnae($campos[11] ?? null),
                ];
            }
        } finally {
            fclose($stream);
            $zip->close();
        }

        return $linhas;
    }

    /**
     * Capital e porte das empresas dos CNPJs achados, indexados pela raiz (8 dígitos) —
     * em memória, só durante a carga.
     *
     * @param  array<string, mixed>  $achados
     * @param  list<string>|null  $locais  zips já baixados; null = baixar os 10
     * @return array<string, array{0: ?string, 1: ?string}>  raiz => [capital, porte]
     */
    private function capitalEPorte(array $achados, ?array $locais, ?string $referencia): array
    {
        $raizes = [];
        foreach (array_keys($achados) as $cnpj) {
            $raizes[substr((string) $cnpj, 0, 8)] = true;
        }

        $empresas = [];
        $arquivos = $locais ?? range(0, (int) config('receita.base.arquivos') - 1);

        foreach ($arquivos as $arquivo) {
            $inicio = microtime(true);
            $caminho = is_int($arquivo) ? $this->baixar($referencia, $arquivo, 'Empresas') : $arquivo;

            try {
                $linhas = $this->varrerEmpresas($caminho, $raizes, $empresas);
            } finally {
                if (is_int($arquivo)) {
                    @unlink($caminho);
                }
            }

            $this->line(sprintf('  %s: %s linhas, %s empresas achadas (%.0f s)',
                basename($caminho), number_format($linhas, 0, ',', '.'),
                number_format(count($empresas), 0, ',', '.'), microtime(true) - $inicio));
        }

        return $empresas;
    }

    /**
     * Varre um zip de Empresas. Layout (sem cabeçalho, `;`, aspas, latin1):
     * 0 cnpj_basico · 1 razão social · 2 natureza · 3 qualificação · 4 capital social
     * ("1000,00") · 5 porte ("01", "03", "05"; "00" = não informado) · 6 ente federativo.
     *
     * @param  array<string, true>  $raizes
     * @param  array<string, array{0: ?string, 1: ?string}>  $empresas
     */
    private function varrerEmpresas(string $caminho, array $raizes, array &$empresas): int
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true || $zip->numFiles < 1) {
            throw new RuntimeException("Não consegui abrir o zip da Receita: {$caminho}");
        }

        $stream = $zip->getStream($zip->getNameIndex(0));
        if ($stream === false) {
            throw new RuntimeException("Zip da Receita sem conteúdo legível: {$caminho}");
        }

        $linhas = 0;

        try {
            while (($linha = fgets($stream)) !== false) {
                $linhas++;

                $raiz = $linha[0] === '"' && ($linha[9] ?? '') === '"'
                    ? substr($linha, 1, 8)
                    : str_pad(str_getcsv($linha, ';', '"', '')[0] ?? '', 8, '0', STR_PAD_LEFT);

                if (! isset($raizes[$raiz])) {
                    continue;
                }

                $campos = str_getcsv($linha, ';', '"', '');
                $capital = trim((string) ($campos[4] ?? ''));

                $empresas[$raiz] = [
                    // "1.234,50" ou "1234,50" → "1234.50"
                    $capital === '' ? null : number_format((float) str_replace(['.', ','], ['', '.'], $capital), 2, '.', ''),
                    PorteEmpresa::deCodigo($campos[5] ?? null),
                ];
            }
        } finally {
            fclose($stream);
            $zip->close();
        }

        return $linhas;
    }

    /** Só os 7 dígitos; qualquer outra coisa (vazio, "0", lixo) vira nulo. */
    private static function cnae(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return strlen($digitos) === 7 ? $digitos : null;
    }

    private function cnpjPorCsv(string $linha): string
    {
        $c = str_getcsv($linha, ';', '"', '');

        return str_pad($c[0] ?? '', 8, '0', STR_PAD_LEFT)
            .str_pad($c[1] ?? '', 4, '0', STR_PAD_LEFT)
            .str_pad($c[2] ?? '', 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, array{0: string, 1: ?string, 2: ?string}>  $achados
     * @param  array<string, true>  $inexistentes
     * @param  array<string, array{0: ?string, 1: ?string}>|null  $empresas  raiz => [capital,
     *         porte]; null = a etapa de Empresas não rodou, e capital/porte não são tocados
     */
    private function gravar(array $achados, array $inexistentes, ?string $referencia, ?array $empresas): void
    {
        $agora = now();
        $linhas = [];

        foreach ($achados as $cnpj => [$situacao, $data, $cnae]) {
            $linhas[] = [(string) $cnpj, $situacao, $data, $cnae];
        }
        foreach (array_keys($inexistentes) as $cnpj) {
            $linhas[] = [(string) $cnpj, SituacaoCadastral::INEXISTENTE, null, null];
        }

        // A varredura de Estabelecimentos sempre roda inteira: o CNAE é sempre regravado.
        $atualizar = ['situacao', 'data_situacao', 'cnae_principal', 'fonte', 'referencia', 'atualizado_em'];
        if ($empresas !== null) {
            $atualizar = [...$atualizar, 'capital_social', 'porte'];
        }

        foreach (array_chunk($linhas, 2000) as $lote) {
            DB::table('cnpj_situacoes')->upsert(array_map(function ($l) use ($referencia, $agora, $empresas) {
                $linha = [
                    'cnpj' => $l[0],
                    'situacao' => $l[1],
                    'data_situacao' => $l[2],
                    'cnae_principal' => $l[3],
                    'fonte' => SituacaoCadastral::FONTE_BASE,
                    'referencia' => $referencia,
                    'atualizado_em' => $agora,
                ];

                if ($empresas !== null) {
                    [$linha['capital_social'], $linha['porte']] = $empresas[substr($l[0], 0, 8)] ?? [null, null];
                }

                return $linha;
            }, $lote), ['cnpj'], $atualizar);
        }
    }

    private function jaCarregada(string $referencia): bool
    {
        return DB::table('cnpj_situacoes')->where('fonte', SituacaoCadastral::FONTE_BASE)
            ->where('referencia', $referencia)->exists();
    }

    private function referenciaMaisRecente(): string
    {
        $resposta = $this->webdav()->withHeaders(['Depth' => '1'])
            ->send('PROPFIND', config('receita.base.webdav').'/')->throw();

        preg_match_all('#/(\d{4}-\d{2})/</d:href>#', $resposta->body(), $m);

        if ($m[1] === []) {
            throw new RuntimeException('Não achei nenhum mês publicado na base aberta da Receita.');
        }

        sort($m[1]);

        return end($m[1]);
    }

    private function baixar(string $referencia, int $indice, string $tipo = 'Estabelecimentos'): string
    {
        $pasta = config('receita.base.diretorio');
        @mkdir($pasta, 0775, true);
        $destino = "{$pasta}/{$tipo}{$indice}.zip";
        $url = config('receita.base.webdav')."/{$referencia}/{$tipo}{$indice}.zip";

        try {
            $this->webdav()->timeout(3600)->withOptions(['sink' => $destino])->get($url)->throw();
        } catch (Throwable $e) {
            @unlink($destino);
            throw new RuntimeException("Falha ao baixar {$url}: {$e->getMessage()}", 0, $e);
        }

        return $destino;
    }

    private function webdav(): \Illuminate\Http\Client\PendingRequest
    {
        // Compartilhamento público do Nextcloud da Receita: o token do link é o usuário.
        return Http::withBasicAuth((string) config('receita.base.token'), '')->connectTimeout(30);
    }
}
