<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategica;
use App\Models\Lead;
use App\Models\Segmento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Liga os leads da prospecção às contas-alvo da Maiores por Segmento — a "mescla" do que
 * a diretoria já tinha com o que o time de prospecção traz (decisão do Tony, 2026-10-02).
 *
 * Só vale para os seis segmentos da planilha (`AbasDaPlanilha`). Lead de outro segmento
 * fica só em /leads.
 *
 * | coluna `rede` do CSV          | o que acontece                                         |
 * |-------------------------------|--------------------------------------------------------|
 * | bate com conta do segmento    | lead ligado a ela, CONFIRMADO                          |
 * | preenchida e não bate         | conta NOVA criada na aba, lead ligado, CONFIRMADO      |
 * | vazia                         | SUGESTÃO pelo nome (mesma heurística da carga da       |
 * |                               | planilha), esperando alguém confirmar na tela          |
 *
 * ⚠️ Lead com `rede` vazia e sem sugestão NÃO vira conta: se virasse, todo lead avulso
 * apareceria como "maior por segmento" e a lista perderia o sentido.
 *
 * ⚠️ Sugestão só é feita para lead que nunca teve ligação: a confirmada é decisão de
 * alguém, e a recusada guarda a conta justamente para não ser sugerida de novo. A coluna
 * `rede` preenchida, por outro lado, é explícita e vence o que houver.
 *
 * ⚠️ A ligação NÃO mexe no status da conta (derivado das lojas nossas, ver
 * `MaioresPorSegmentoResolver`). Conta só com lead continua "Lead".
 */
class ContaDoLead
{
    public function __construct(
        private readonly SugestaoDeVinculo $sugestao,
    ) {
    }

    /**
     * @param  list<array{lead_id: int, segmento: ?string, rede: ?string, filiais: ?int, uf: ?string, nome: string}>  $itens
     * @return array{criadas: int, confirmados: int, sugeridos: int, ambiguos: int, foraDasAbas: int, detalhe: array{redesCriadas: list<string>, ligados: array<int, string>}}
     *
     * `detalhe` é o que a /atualizacoes lista no clique: as redes criadas e, por lead
     * (id), o nome da rede a que ele foi ligado nesta rodada.
     */
    public function vincularDaProspeccao(array $itens, bool $dryRun = false): array
    {
        $stats = ['criadas' => 0, 'confirmados' => 0, 'sugeridos' => 0, 'ambiguos' => 0, 'foraDasAbas' => 0,
            'detalhe' => ['redesCriadas' => [], 'ligados' => []]];

        $abas = AbasDaPlanilha::codigos();
        $segmentoId = Segmento::query()->whereIn('codigo', $abas)->pluck('id', 'codigo');

        $itens = collect($itens)->filter(function (array $i) use ($segmentoId, &$stats) {
            $dentro = $i['segmento'] !== null && $segmentoId->has($i['segmento']);
            if (! $dentro && filled($i['rede'])) {
                $stats['foraDasAbas']++;
            }

            return $dentro;
        })->values();

        if ($itens->isEmpty()) {
            return $stats;
        }

        $contas = ContaEstrategica::query()
            ->whereIn('segmento_id', $segmentoId->values())
            ->get(['id', 'segmento_id', 'nome', 'filiais_mercado', 'ordem']);
        $codigoDoSegmento = $segmentoId->flip();

        /** @var array<string, ContaEstrategica|int> "seg|chave" → conta (ou id provisório no dry-run) */
        $porNome = [];
        foreach ($contas as $c) {
            $porNome[$codigoDoSegmento[$c->segmento_id].'|'.$this->sugestao->chaveDeNome($c->nome)] = $c;
        }

        $estado = DB::table('leads')->whereIn('id', $itens->pluck('lead_id'))
            ->get(['id', 'conta_estrategica_id', 'conta_vinculo'])->keyBy('id');

        $ligar = [];
        $semRede = collect();

        foreach ($itens as $i) {
            if (blank($i['rede'])) {
                if (($estado[$i['lead_id']]->conta_vinculo ?? null) === null) {
                    $semRede->push($i);
                }

                continue;
            }

            $chave = $i['segmento'].'|'.$this->sugestao->chaveDeNome($i['rede']);
            $conta = $porNome[$chave] ?? null;

            if ($conta === null) {
                $stats['criadas']++;
                $stats['detalhe']['redesCriadas'][] = trim($i['rede']);
                $conta = $porNome[$chave] = $dryRun ? -$stats['criadas'] : $this->criarConta($segmentoId[$i['segmento']], $i);
            } elseif (! $dryRun && $conta->filiais_mercado === null && $i['filiais']) {
                // Conta sem o número de filiais aprende com o CSV; número já digitado nunca é sobrescrito.
                $conta->forceFill(['filiais_mercado' => $i['filiais']])->save();
            }

            $contaId = is_int($conta) ? $conta : $conta->id;
            $atual = $estado[$i['lead_id']] ?? null;

            if ($atual?->conta_vinculo === Lead::CONTA_CONFIRMADA && (int) $atual->conta_estrategica_id === $contaId) {
                continue;
            }

            $ligar[] = [$i['lead_id'], $contaId, Lead::CONTA_CONFIRMADA];
            $stats['confirmados']++;
            $stats['detalhe']['ligados'][$i['lead_id']] = is_int($conta) ? trim($i['rede']) : $conta->nome;
        }

        foreach ($this->sugerir($semRede, $contas, $codigoDoSegmento) as $leadId => $contaIds) {
            if (count($contaIds) > 1) {
                $stats['ambiguos']++;

                continue;
            }

            $ligar[] = [$leadId, $contaIds[0], Lead::CONTA_SUGERIDA];
            $stats['sugeridos']++;
        }

        if (! $dryRun) {
            foreach ($ligar as [$leadId, $contaId, $vinculo]) {
                DB::table('leads')->where('id', $leadId)
                    ->update(['conta_estrategica_id' => $contaId, 'conta_vinculo' => $vinculo]);
            }
        }

        return $stats;
    }

    /**
     * Sugestões ainda sem decisão, para a lista da Visão Diretor. Lead excluído não entra:
     * não há o que decidir sobre ele.
     *
     * @return list<array<string, mixed>>
     */
    public function sugestoesPendentes(): array
    {
        return Lead::query()->visivel()
            ->where('conta_vinculo', Lead::CONTA_SUGERIDA)
            ->with('contaEstrategica:id,nome,segmento_id', 'contaEstrategica.segmento:id,nome')
            ->orderBy('conta_estrategica_id')->orderBy('razao_social')
            ->get(['id', 'nome', 'razao_social', 'cnpj', 'cidade', 'estado', 'conta_estrategica_id'])
            ->filter(fn (Lead $l) => $l->contaEstrategica !== null)
            ->map(fn (Lead $l) => [
                'leadId' => $l->id,
                'leadNome' => $l->nome,
                'razaoSocial' => $l->razao_social,
                'cnpj' => $l->cnpj,
                'local' => collect([$l->cidade, $l->estado])->filter()->implode(' / ') ?: null,
                'contaId' => $l->conta_estrategica_id,
                'contaNome' => $l->contaEstrategica->nome,
                'segmento' => $l->contaEstrategica->segmento?->nome,
            ])
            ->values()
            ->all();
    }

    public function confirmar(Lead $lead): void
    {
        abort_unless($lead->conta_vinculo === Lead::CONTA_SUGERIDA, 422, 'Este lead não tem sugestão pendente.');

        $lead->forceFill(['conta_vinculo' => Lead::CONTA_CONFIRMADA])->save();
    }

    /** Mantém a conta gravada: é o que impede o próximo import de sugerir a mesma. */
    public function recusar(Lead $lead): void
    {
        abort_unless($lead->conta_vinculo === Lead::CONTA_SUGERIDA, 422, 'Este lead não tem sugestão pendente.');

        $lead->forceFill(['conta_vinculo' => Lead::CONTA_RECUSADA])->save();
    }

    /**
     * Para cada lead sem `rede`, as contas do MESMO segmento cujo nome casa com o dele.
     * Mesma heurística da carga da planilha, só que com os leads no lugar dos grupos:
     * a conta é o nome curto ("RAIA DROGASIL"), o lead é o nome legal ("RAIA DROGASIL S/A").
     *
     * @param  Collection<int, array>  $leads
     * @param  Collection<int, ContaEstrategica>  $contas
     * @param  Collection<int, string>  $codigoDoSegmento  segmento_id → código
     * @return array<int, list<int>> lead_id → contas candidatas
     */
    private function sugerir(Collection $leads, Collection $contas, Collection $codigoDoSegmento): array
    {
        $candidatos = [];

        foreach ($leads->groupBy('segmento') as $segmento => $doSegmento) {
            // Duas entradas por lead (razão social e nome fantasia), mesmo código: casar qualquer uma basta.
            $catalogo = $this->sugestao->catalogoDeNomes(
                $doSegmento->flatMap(fn (array $l) => collect($l['nomes'] ?? [$l['nome']])
                    ->filter()->unique()
                    ->map(fn (string $n) => ['codigo' => (string) $l['lead_id'], 'nome' => $n, 'segmento' => (string) $segmento]))
            );

            // ⚠️ (string) dos dois lados: código numérico vira chave INTEIRA de array ('109' → 109).
            foreach ($contas->filter(fn ($c) => (string) $codigoDoSegmento[$c->segmento_id] === (string) $segmento) as $conta) {
                $achados = $this->sugestao->sugerir($conta->nome, (string) $segmento, $catalogo);

                foreach ($achados['grupos']->pluck('codigo')->unique() as $leadId) {
                    $candidatos[(int) $leadId][] = $conta->id;
                }
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $candidatos);
    }

    /** @param  array{rede: string, uf: ?string, filiais: ?int}  $item */
    private function criarConta(int $segmentoId, array $item): ContaEstrategica
    {
        $ordem = (int) ContaEstrategica::query()->where('segmento_id', $segmentoId)->max('ordem');

        return ContaEstrategica::query()->create([
            'segmento_id' => $segmentoId,
            'nome' => mb_substr(trim($item['rede']), 0, 150),
            'uf' => $item['uf'],
            'filiais_mercado' => $item['filiais'],
            // No fim da aba: as contas da planilha seguem na ordem que a diretoria deu.
            'ordem' => $ordem + 1,
        ]);
    }
}
