<?php

namespace App\Console\Commands;

use App\Models\IaConversa;
use App\Models\IaMensagem;
use App\Models\User;
use App\Services\Escopo\ModoVisao;
use App\Services\Ia\ClienteClaude;
use App\Services\Ia\ClienteClaudeAnthropic;
use App\Services\Ia\Orquestrador;
use App\Services\Ia\PromptDoSistema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Roda o conjunto de perguntas de resources/ia/avaliacao.json contra um modelo de
 * verdade e diz quantas o assistente acertou, com latência e custo.
 *
 * É a Regra de ouro nº 6 aplicada à IA: "respondeu bem a minha pergunta" não prova nada.
 * A troca de modelo (Opus ↔ Haiku) e qualquer mudança grande no manual ou nas
 * instruções passam por aqui antes de ir para produção.
 *
 * ⚠️ GASTA DINHEIRO DE VERDADE — cada pergunta é uma chamada paga à API. Por isso pede
 * confirmação e mostra o número de perguntas antes.
 *
 * ⚠️ Cada pergunta roda numa transação desfeita no fim: as consultas leem o banco real,
 * mas nenhuma conversa ou chamado de teste fica gravado.
 *
 * Correção simples, sem um segundo modelo de juiz: a ferramenta esperada foi chamada? O
 * texto contém o que deve e não contém o que não pode? Barato, reproduzível e suficiente
 * para comparar dois modelos no mesmo conjunto.
 */
class AvaliarAssistenteIa extends Command
{
    protected $signature = 'ia:avaliar
        {--modelo= : Modelo a avaliar (padrão: config ia.modelo)}
        {--so= : Roda só os casos cujo id contém este texto}
        {--force : Não pede confirmação}';

    protected $description = 'Avalia o assistente de IA no conjunto de perguntas (chama a API de verdade)';

    public function handle(PromptDoSistema $prompt): int
    {
        if (config('ia.api_key') === '') {
            $this->error('Sem ANTHROPIC_API_KEY: a avaliação precisa do modelo de verdade.');

            return self::FAILURE;
        }

        // Nunca o roteiro de demonstração, mesmo em ambiente local.
        app()->instance(ClienteClaude::class, new ClienteClaudeAnthropic);
        $orquestrador = app(Orquestrador::class);

        $modelo = $this->option('modelo') ?: (string) config('ia.modelo');
        $casos = json_decode((string) file_get_contents(resource_path('ia/avaliacao.json')), true)['casos'] ?? [];
        if ($so = $this->option('so')) {
            $casos = array_values(array_filter($casos, fn ($c) => str_contains($c['id'], $so)));
        }

        if (! $this->option('force') && ! $this->confirm(count($casos)." perguntas no modelo {$modelo}. Isso chama a API e gera custo. Continuar?")) {
            return self::SUCCESS;
        }

        // Transmitir ao Reverb não faz sentido aqui.
        Event::fake([\App\Events\IaEvento::class]);

        $linhas = [];
        $acertos = 0;
        $custo = 0.0;

        foreach ($casos as $caso) {
            $user = User::query()->where('email', $caso['usuario'])->first();
            if ($user === null) {
                $this->warn("[{$caso['id']}] usuário {$caso['usuario']} não existe neste banco — pulado.");

                continue;
            }

            ModoVisao::restaurarSemSessao($caso['modo'] ?? null);

            DB::beginTransaction();
            try {
                $conversa = IaConversa::create(['user_id' => $user->id, 'titulo' => 'avaliação', 'modelo' => $modelo]);
                $conversa->mensagens()->create([
                    'papel' => 'usuario',
                    'texto' => $caso['pergunta'],
                    'conteudo_api' => [[
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => $prompt->contexto($user, $caso['tela'] ?? null, now())."\n\n".$caso['pergunta']]],
                    ]],
                ]);
                $resposta = $conversa->mensagens()->create(['papel' => 'ia', 'status' => IaMensagem::STATUS_PROCESSANDO]);

                $orquestrador->responder($resposta, $modelo);
                $resposta->refresh();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error("[{$caso['id']}] falhou: {$e->getMessage()}");
                $linhas[] = ['caso' => $caso, 'ok' => false, 'motivos' => ['erro: '.$e->getMessage()], 'resposta' => null];

                continue;
            }
            DB::rollBack();

            [$ok, $motivos] = $this->corrigir($caso, $resposta);
            $acertos += $ok ? 1 : 0;
            $custo += (float) $resposta->custo_usd;
            $linhas[] = ['caso' => $caso, 'ok' => $ok, 'motivos' => $motivos, 'resposta' => $resposta];

            $this->line(sprintf('%s %-28s %5.1fs  US$ %.4f  %s',
                $ok ? '<info>✓</info>' : '<error>✗</error>',
                $caso['id'],
                ($resposta->latencia_ms ?? 0) / 1000,
                $resposta->custo_usd,
                $ok ? '' : implode('; ', $motivos),
            ));
        }

        $total = count($linhas);
        $this->newLine();
        $this->info("{$acertos}/{$total} acertos · US$ ".number_format($custo, 4).' no total');

        $arquivo = 'relatorios/ia-avaliacao-'.str_replace('.', '-', $modelo).'-'.now()->format('Ymd-His').'.md';
        $caminho = storage_path('app/'.$arquivo);
        @mkdir(dirname($caminho), 0775, true);
        file_put_contents($caminho, $this->relatorio($modelo, $linhas, $acertos, $custo));
        $this->line("Relatório: storage/app/{$arquivo}");

        return self::SUCCESS;
    }

    /** @return array{0: bool, 1: list<string>} */
    private function corrigir(array $caso, IaMensagem $resposta): array
    {
        $motivos = [];
        $texto = mb_strtolower((string) $resposta->texto);
        $chamadas = $resposta->ferramentas ?? [];

        foreach ($caso['ferramentas'] ?? [] as $esperada) {
            if (! in_array($esperada, $chamadas, true)) {
                $motivos[] = "não chamou {$esperada}";
            }
        }
        foreach ($caso['deve_conter'] ?? [] as $trecho) {
            if (! str_contains($texto, mb_strtolower($trecho))) {
                $motivos[] = "faltou \"{$trecho}\"";
            }
        }
        foreach ($caso['nao_pode_conter'] ?? [] as $trecho) {
            if (str_contains($texto, mb_strtolower($trecho))) {
                $motivos[] = "disse \"{$trecho}\"";
            }
        }

        return [$motivos === [], $motivos];
    }

    private function relatorio(string $modelo, array $linhas, int $acertos, float $custo): string
    {
        $latencias = array_filter(array_map(fn ($l) => $l['resposta']?->latencia_ms, $linhas));
        sort($latencias);
        $p50 = $latencias ? $latencias[(int) floor((count($latencias) - 1) / 2)] : 0;
        $p95 = $latencias ? $latencias[(int) floor((count($latencias) - 1) * 0.95)] : 0;

        $md = "# Avaliação do assistente — {$modelo}\n\n"
            .now()->format('d/m/Y H:i').' · '.$acertos.'/'.count($linhas).' acertos · US$ '.number_format($custo, 4)
            ." · latência p50 ".round($p50 / 1000, 1).' s, p95 '.round($p95 / 1000, 1)." s\n\n";

        foreach ($linhas as $l) {
            $r = $l['resposta'];
            $md .= '## '.($l['ok'] ? '✓' : '✗').' '.$l['caso']['id']."\n\n"
                .'**Pergunta** ('.$l['caso']['usuario'].'): '.$l['caso']['pergunta']."\n\n"
                .($l['motivos'] ? '**Falhas:** '.implode('; ', $l['motivos'])."\n\n" : '')
                .($r ? '**Ferramentas:** '.(implode(', ', $r->ferramentas ?? []) ?: 'nenhuma')
                    .' · '.round(($r->latencia_ms ?? 0) / 1000, 1).' s · US$ '.number_format((float) $r->custo_usd, 4)."\n\n"
                    .'> '.str_replace("\n", "\n> ", (string) $r->texto)."\n\n" : '');
        }

        return $md;
    }
}
