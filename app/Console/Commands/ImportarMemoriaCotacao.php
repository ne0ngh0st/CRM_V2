<?php

namespace App\Console\Commands;

use App\Models\CotacaoDecisao;
use App\Models\CotacaoMemoria;
use App\Models\User;
use App\Services\Cotacao\CotacaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Traz para o CRM as decisões do antigo Cotacao.exe.
 *
 * Aceita os dois formatos que existem fora daqui:
 *   - o JSON do `?acao=snapshot` da API da KingHost (`{decisoes: [...]}`);
 *   - o `decisoes.csv` / fila local que cada .exe guardava ao lado do programa.
 *
 * ⚠️ A chave é RECALCULADA pelo motor a partir de `descricao_cliente`, nunca copiada do
 * arquivo: se o dicionário de sinônimos do CRM já tiver mudado, a chave antiga deixaria
 * de casar sem erro nenhum.
 *
 * Idempotente: decisão igual à que já está na memória é pulada. Se o CRM já tiver uma
 * decisão DIFERENTE para o mesmo texto, a do CRM vence (é mais nova que o .exe) e o
 * conflito é listado.
 */
class ImportarMemoriaCotacao extends Command
{
    protected $signature = 'cotacao:importar-memoria
        {arquivo : JSON do snapshot da API antiga ou CSV de decisões do .exe}
        {--dry-run : mostra o que faria, sem gravar}';

    protected $description = 'Importa decisões do antigo Cotacao.exe para a memória da Cotação';

    public function handle(CotacaoService $servico): int
    {
        $arquivo = $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $registros = str_ends_with(strtolower($arquivo), '.json') ? $this->lerJson($arquivo) : $this->lerCsv($arquivo);
        $registros = array_values(array_filter(
            $registros,
            fn ($r) => in_array($r['decisao'], ['OK', 'TROCAR', 'NAO_TEMOS'], true) && $r['descricao_cliente'] !== ''
        ));

        if ($registros === []) {
            $this->warn('Nenhuma decisão válida no arquivo.');

            return self::SUCCESS;
        }

        $chaves = $servico->normalizar(array_column($registros, 'descricao_cliente'));
        $usuarios = $this->usuariosPorApelido();
        $dryRun = (bool) $this->option('dry-run');

        $novas = $iguais = $conflitos = $chaveMudou = 0;

        DB::transaction(function () use ($registros, $chaves, $usuarios, $dryRun, &$novas, &$iguais, &$conflitos, &$chaveMudou) {
            foreach ($registros as $i => $r) {
                $chave = $chaves[$i];
                if ($r['texto_normalizado'] !== '' && $r['texto_normalizado'] !== $chave) {
                    $chaveMudou++;
                }

                $codigo = $r['decisao'] === 'NAO_TEMOS' ? null : ($r['codigo'] ?: null);
                $atual = CotacaoMemoria::find($chave);

                if ($atual !== null) {
                    if ($atual->decisao === $r['decisao'] && $atual->codigo === $codigo) {
                        $iguais++;
                    } else {
                        $conflitos++;
                        $this->line("  conflito (fica a do CRM): {$r['descricao_cliente']}");
                    }

                    continue;
                }

                $novas++;

                if ($dryRun) {
                    continue;
                }

                $quando = $r['data'] !== '' ? Carbon::parse($r['data']) : now();
                $userId = $usuarios[mb_strtolower($r['usuario'])] ?? null;

                $registro = CotacaoDecisao::create([
                    'texto_normalizado' => $chave,
                    'descricao_cliente' => $r['descricao_cliente'],
                    'decisao' => $r['decisao'],
                    'codigo' => $codigo,
                    'descricao_nossa' => $r['descricao_nossa'] ?: null,
                    'user_id' => $userId,
                    'origem' => mb_substr(trim('Cotacao.exe · '.$r['usuario'].' · '.$r['origem']), 0, 255),
                    'created_at' => $quando,
                ]);

                CotacaoMemoria::create([
                    'texto_normalizado' => $chave,
                    'descricao_cliente' => $r['descricao_cliente'],
                    'decisao' => $r['decisao'],
                    'codigo' => $codigo,
                    'descricao_nossa' => $r['descricao_nossa'] ?: null,
                    'user_id' => $userId,
                    'quem' => mb_substr($r['usuario'], 0, 120) ?: null,
                    'decisao_id' => $registro->id,
                    'atualizado_em' => $quando,
                ]);
            }
        });

        $this->info(sprintf(
            '%s%d decisões novas, %d já estavam iguais, %d conflitos (mantida a do CRM), %d com chave recalculada.',
            $dryRun ? '[dry-run] ' : '',
            $novas, $iguais, $conflitos, $chaveMudou
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function lerJson(string $arquivo): array
    {
        $dados = json_decode(file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($d) => $this->normalizarRegistro($d), $dados['decisoes'] ?? $dados);
    }

    /** @return list<array<string, string>> */
    private function lerCsv(string $arquivo): array
    {
        $h = fopen($arquivo, 'r');
        $cabecalho = fgetcsv($h);
        $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0]); // BOM do Excel
        $saida = [];

        while (($linha = fgetcsv($h)) !== false) {
            if (count($linha) === count($cabecalho)) {
                $saida[] = $this->normalizarRegistro(array_combine($cabecalho, $linha));
            }
        }
        fclose($h);

        return $saida;
    }

    private function normalizarRegistro(array $d): array
    {
        return [
            'texto_normalizado' => trim((string) ($d['texto_normalizado'] ?? '')),
            'descricao_cliente' => trim((string) ($d['descricao_cliente'] ?? '')),
            'decisao' => strtoupper(trim((string) ($d['decisao'] ?? ''))),
            'codigo' => trim((string) ($d['codigo'] ?? '')),
            'descricao_nossa' => trim((string) ($d['descricao_nossa'] ?? '')),
            'usuario' => trim((string) ($d['usuario'] ?? '')),
            'data' => trim((string) ($d['data'] ?? '')),
            'origem' => trim((string) ($d['origem'] ?? '')),
        ];
    }

    /**
     * O .exe guardava o usuário do Windows ("Sthefany.Santos"). Casa com o `username` do
     * CRM ou com a parte antes do @ do e-mail; sem casar, fica só o nome em `quem`.
     *
     * @return array<string, int>
     */
    private function usuariosPorApelido(): array
    {
        $mapa = [];

        foreach (User::query()->get(['id', 'username', 'email']) as $u) {
            if ($u->username) {
                $mapa[mb_strtolower($u->username)] = $u->id;
            }
            if ($u->email && str_contains($u->email, '@')) {
                $mapa[mb_strtolower(strstr($u->email, '@', true))] = $u->id;
            }
        }

        return $mapa;
    }
}
