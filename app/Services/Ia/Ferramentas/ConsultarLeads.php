<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\Lead;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Funil de leads do escopo, ou a busca de um lead pelo nome.
 *
 * "Em jogo" é a esteira (novo → negociação). Outros, ganho e perdido ficam de fora
 * dessa conta — a mesma regra do quadro.
 */
final class ConsultarLeads implements Ferramenta
{
    private const LIMITE = 8;

    /** @var array<string, string> */
    private const ROTULOS = [
        'novo' => 'Novo',
        'em_contato' => 'Em contato',
        'orcamento' => 'Orçamento',
        'negociacao' => 'Negociação',
        'outros' => 'Outros',
        'ganho' => 'Ganho',
        'perdido' => 'Perdido',
    ];

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly NomeVendedorResolver $nomes,
    ) {}

    public function nome(): string
    {
        return 'consultar_leads';
    }

    public function rotuloProgresso(): string
    {
        return 'Consultando os leads…';
    }

    public function descricao(): string
    {
        return 'Leads do escopo. Sem termo, devolve o funil por etapa e quantos estão em jogo '
            .'(Novo até Negociação; Outros, Ganho e Perdido não entram nessa conta). Com termo, '
            .'procura pelo nome ou razão social e diz a etapa e o vendedor. Use para "quantos '
            .'leads em negociação", "de quem é o lead da empresa X". Não traz o texto da '
            .'observação nem a mensagem do formulário do site.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'termo' => ['type' => ['string', 'null'], 'description' => 'Nome ou razão social, ou null para o funil.'],
            ],
            'required' => ['termo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $termo = trim((string) ($entrada['termo'] ?? ''));
        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $base = Lead::query()
            ->visivel()
            ->when($codigos === [], fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when(is_array($codigos) && $codigos !== [], fn (Builder $q) => $q->whereIn('cod_vendedor', $codigos));

        if ($termo !== '') {
            $como = '%'.str_replace(['%', '_'], '', $termo).'%';
            $leads = (clone $base)
                ->where(fn ($q) => $q->where('razao_social', 'like', $como)
                    ->orWhere('nome', 'like', $como)
                    ->orWhere('nome_fantasia', 'like', $como))
                ->orderByDesc('id')
                ->limit(self::LIMITE)
                ->get();

            $nomes = $this->nomes->porCodigo($leads->pluck('cod_vendedor'));

            return [
                'leads' => $leads->map(fn (Lead $l) => [
                    'nome' => $l->razao_social ?: $l->nome,
                    'cnpj' => $l->cnpj,
                    'etapa' => self::ROTULOS[$l->etapa] ?? $l->etapa,
                    'origem' => $l->origem,
                    'vendedor' => [
                        'nome' => $nomes[$l->cod_vendedor] ?? null,
                        'codigo' => $l->cod_vendedor,
                    ],
                ])->all(),
                'mensagem' => $leads->isEmpty() ? 'Nenhum lead com esse nome no escopo do usuário.' : null,
            ];
        }

        $contagem = (clone $base)
            ->selectRaw('etapa, COUNT(*) as qtd')
            ->groupBy('etapa')
            ->pluck('qtd', 'etapa');

        $etapas = [];
        foreach (Lead::ETAPAS as $etapa) {
            $etapas[] = [
                'etapa' => self::ROTULOS[$etapa] ?? $etapa,
                'quantidade' => (int) ($contagem[$etapa] ?? 0),
            ];
        }

        $emJogo = 0;
        foreach (Lead::ETAPAS_ESTEIRA as $etapa) {
            $emJogo += (int) ($contagem[$etapa] ?? 0);
        }

        return [
            'em_jogo' => $emJogo,
            'etapas' => $etapas,
            'definicao' => 'Em jogo é a esteira (Novo, Em contato, Orçamento, Negociação). Outros não é venda.',
        ];
    }
}
