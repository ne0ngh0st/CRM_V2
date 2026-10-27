<?php

namespace App\Services\Ia\Ferramentas;

use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;
use App\Services\Ia\Formato;
use App\Services\Metas\MetaRankingResolver;

/**
 * Meta × realizado do /metas, no escopo de quem pergunta.
 *
 * Sem nome: totais do escopo e quem está mais atrás da meta de faturamento.
 * Com nome: a linha daquela pessoa, se ela estiver no escopo. Fora do escopo é a
 * mesma resposta de "não achei" — não confirma que a pessoa existe.
 */
final class DesempenhoComercial implements Ferramenta
{
    private const ATRAS = 8;

    public function __construct(
        private readonly DashboardScopeResolver $escopo,
        private readonly MetaRankingResolver $metas,
    ) {}

    public function nome(): string
    {
        return 'desempenho_comercial';
    }

    public function rotuloProgresso(): string
    {
        return 'Calculando o desempenho…';
    }

    public function descricao(): string
    {
        return 'Desempenho comercial do escopo (carteira, equipe ou empresa): meta e realizado '
            .'de venda (pedido) e de faturamento (nota), até o último dia fechado, mais o que '
            .'falta vender no mês (meta de faturamento − faturado até ontem − pedidos em aberto '
            .'que ainda podem faturar no mês). Com nome, a linha daquela pessoa. Sem nome, os '
            .'totais e quem está mais atrás da meta de faturamento. Use para "como está a '
            .'equipe", "a Fulana bateu a meta?", "quem está atrás", "falta vender".';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nome' => ['type' => ['string', 'null'], 'description' => 'Nome da pessoa, ou null para o escopo inteiro.'],
                'ano' => ['type' => ['integer', 'null'], 'description' => 'Ano, ou null para o corrente.'],
                'mes' => ['type' => ['integer', 'null'], 'description' => 'Mês 1-12, ou null para o corrente.'],
            ],
            'required' => ['nome', 'ano', 'mes'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $ano = (int) ($entrada['ano'] ?? 0) ?: (int) now()->year;
        $mes = (int) ($entrada['mes'] ?? 0) ?: (int) now()->month;
        $nome = trim((string) ($entrada['nome'] ?? ''));

        if ($mes < 1 || $mes > 12 || $ano < 2018 || $ano > (int) now()->year + 1) {
            return ['erro' => 'Mês ou ano inválido.'];
        }

        $codigos = $this->escopo->resolve($contexto->user, null, null)['codVendedores'];
        $ranking = $this->metas->ranking($codigos, $ano, $mes, 'mensal', $nome);
        $totais = $ranking['totais'];

        $saida = [
            'periodo' => sprintf('%02d/%d', $mes, $ano),
            'escopo' => Formato::escopo($codigos, $contexto->user->getRoleNames()->first()),
            'realizado_ate' => $ranking['periodo']['fim'] >= $ranking['periodo']['inicio']
                ? Formato::data(\Carbon\Carbon::parse($ranking['periodo']['fim']))
                : 'mês ainda sem dia fechado',
            'totais' => [
                'venda' => $this->par($totais['vendaMeta'], $totais['vendaRealizado'], $totais['vendaPct']),
                'faturamento' => $this->par($totais['fatMeta'], $totais['fatRealizado'], $totais['fatPct']),
                'em_aberto' => $totais['emAberto'] === null ? null : Formato::reais($totais['emAberto']),
                'falta_vender' => $this->falta($totais['faltaVender']),
            ],
        ];

        if ($nome !== '') {
            $pessoas = array_map(fn (array $l) => $this->linha($l), array_slice($ranking['linhas'], 0, self::ATRAS));

            return $saida + [
                'pessoas' => $pessoas,
                'mensagem' => $pessoas === [] ? 'Ninguém com esse nome no escopo do usuário.' : null,
            ];
        }

        $atras = collect($ranking['linhas'])
            ->filter(fn (array $l) => $l['fatPct'] !== null)
            ->sortBy('fatPct')
            ->take(self::ATRAS)
            ->map(fn (array $l) => $this->linha($l))
            ->values()
            ->all();

        return $saida + [
            'quem_esta_atras' => $atras,
            'kpis' => [
                'bateram_a_meta_de_faturamento' => $ranking['kpis']['atingiu'],
                'abaixo_de_80' => $ranking['kpis']['abaixo'],
                'sem_meta' => $ranking['kpis']['semMeta'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function par(float $meta, float $realizado, ?float $pct): array
    {
        return [
            'meta' => Formato::reais($meta),
            'realizado' => Formato::reais($realizado),
            'percentual' => $pct === null ? 'sem meta' : $pct.'%',
        ];
    }

    private function falta(float|null $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return $valor > 0 ? Formato::reais($valor) : 'coberto';
    }

    /**
     * @param  array<string, mixed>  $linha
     * @return array<string, mixed>
     */
    private function linha(array $linha): array
    {
        return [
            'nome' => $linha['nome'],
            'codigo' => $linha['codVendedor'],
            'perfil' => $linha['perfil'],
            'venda' => $this->par((float) $linha['vendaMeta'], (float) $linha['vendaRealizado'], $linha['vendaPct']),
            'faturamento' => $this->par((float) $linha['fatMeta'], (float) $linha['fatRealizado'], $linha['fatPct']),
            'em_aberto' => $linha['emAberto'] === null ? null : Formato::reais($linha['emAberto']),
            'falta_vender' => $this->falta($linha['faltaVender']),
        ];
    }
}
