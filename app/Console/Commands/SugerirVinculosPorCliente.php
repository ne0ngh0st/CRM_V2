<?php

namespace App\Console\Commands;

use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\VinculoPorCliente;
use Illuminate\Console\Command;

/**
 * Liga as contas-alvo da Visão Diretor aos clientes da carteira pelo nome das filiais
 * (`VinculoPorCliente`). Os vínculos entram como SUGESTÃO, revisáveis no modal da conta.
 *
 * ⚠️ Conta com qualquer vínculo `manual` não é tocada: alguém já decidiu por ela.
 * ⚠️ Só ACRESCENTA às sugestões que a conta já tem — nunca remove.
 * ⚠️ Grupo ou código que casa com duas contas fica de fora das duas (vai para o relatório).
 *
 * Seguro rodar de novo: o que já está gravado não muda.
 *
 * `--segmento=101` grava só nas contas daquele segmento (é o que a carga do ranking da
 * ABRAS usa, para não acrescentar sugestão nas outras abas sem ninguém pedir). O conflito
 * "casa com duas contas" continua sendo apurado contra TODAS as contas: senão um grupo da
 * aba nova poderia ir também para uma conta de outra aba.
 */
class SugerirVinculosPorCliente extends Command
{
    protected $signature = 'visao-diretor:sugerir-vinculos
        {--dry-run : mostra o que seria vinculado, sem gravar}
        {--detalhe : lista conta a conta, com exemplos de filiais casadas}
        {--segmento= : só grava nas contas deste segmento (código TOTVS, ex.: 101)}
        {--so-sem-vinculo : só grava em conta que ainda não tem vínculo nenhum}';

    protected $description = 'Sugere vínculos conta-alvo → clientes pelo nome fantasia/razão das filiais';

    public function handle(VinculoPorCliente $vinculo, ClientesDaConta $clientesDaConta): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $catalogo = $vinculo->catalogo();

        $contas = ContaEstrategica::query()
            ->with(['segmento:id,codigo', 'vinculos'])
            ->orderBy('id')
            ->get();

        $propostas = [];
        $donos = [];
        $ambiguas = [];

        foreach ($contas as $conta) {
            if ($conta->vinculos->contains('origem', ContaEstrategicaVinculo::ORIGEM_MANUAL)) {
                continue;
            }

            $r = $vinculo->sugerir($conta->nome, (string) $conta->segmento->codigo, $catalogo);

            if ($r['ambiguo']) {
                $ambiguas[] = $conta->nome;

                continue;
            }

            $novos = [];
            foreach (['grupo' => $r['grupos'], 'cliente' => $r['clientes']] as $tipo => $codigos) {
                foreach ($codigos as $codigo) {
                    $novos[] = "{$tipo}:{$codigo}";
                    $donos["{$tipo}:{$codigo}"][] = $conta->id;
                }
            }

            $propostas[$conta->id] = ['novos' => $novos, 'exemplos' => $r['exemplos']];
        }

        $conflitos = array_filter($donos, fn (array $ids) => count(array_unique($ids)) > 1);
        $antes = $contas->filter(fn ($c) => $c->vinculos->isNotEmpty())->count();
        $alteradas = 0;
        $acrescentados = ['grupo' => 0, 'cliente' => 0];
        $linhas = [];

        $soSegmento = $this->option('segmento');

        foreach ($contas as $conta) {
            $proposta = $propostas[$conta->id] ?? null;
            if (! $proposta || ($soSegmento !== null && (string) $conta->segmento->codigo !== (string) $soSegmento)) {
                continue;
            }

            // O nome de marca é o sinal fraco: não soma a um vínculo já feito pela razão social.
            if ($this->option('so-sem-vinculo') && $conta->vinculos->isNotEmpty()) {
                continue;
            }

            $atuais = $conta->vinculos->map(fn ($v) => "{$v->tipo}:{$v->codigo}")->all();
            $novos = array_values(array_diff(
                array_filter($proposta['novos'], fn (string $k) => ! isset($conflitos[$k])),
                $atuais,
            ));

            if ($novos === []) {
                continue;
            }

            $alteradas++;
            foreach ($novos as $k) {
                $acrescentados[strtok($k, ':')]++;
            }

            $linhas[] = [$conta->segmento->codigo, $conta->nome, implode(' ', $novos), implode(' | ', $proposta['exemplos'])];

            if (! $dryRun) {
                $clientesDaConta->sincronizarVinculos($conta, [
                    ...$conta->vinculos->map(fn ($v) => ['tipo' => $v->tipo, 'codigo' => $v->codigo, 'origem' => $v->origem])->all(),
                    ...array_map(fn (string $k) => [
                        'tipo' => strtok($k, ':'),
                        'codigo' => substr($k, strpos($k, ':') + 1),
                        'origem' => ContaEstrategicaVinculo::ORIGEM_SUGESTAO,
                    ], $novos),
                ]);
            }
        }

        if ($this->option('detalhe') && $linhas !== []) {
            $this->table(['Seg', 'Conta', 'Novos vínculos', 'Exemplos de filiais'], $linhas);
        }

        $semVinculoAntes = $contas->count() - $antes;
        $ganharam = collect($linhas)->filter(fn ($l) => ! $contas->firstWhere('nome', $l[1])?->vinculos->isNotEmpty())->count();

        $this->info(($dryRun ? '[dry-run] ' : '')."Contas: {$contas->count()} · com vínculo antes: {$antes} · sem vínculo antes: {$semVinculoAntes}");
        $this->info("Contas que ganham vínculo: {$alteradas} (das sem vínculo: {$ganharam}) · grupos: {$acrescentados['grupo']} · códigos: {$acrescentados['cliente']}");

        if ($conflitos !== []) {
            $nomes = $contas->keyBy('id');
            $this->warn('Casaram com mais de uma conta (ficaram de fora): '.count($conflitos));
            foreach ($conflitos as $k => $ids) {
                $this->line("  {$k}: ".collect(array_unique($ids))->map(fn ($id) => $nomes[$id]->nome)->implode(' · '));
            }
        }

        if ($ambiguas !== []) {
            $this->warn('Nome comum demais, sem sugestão: '.implode(' · ', $ambiguas));
        }

        return self::SUCCESS;
    }
}
