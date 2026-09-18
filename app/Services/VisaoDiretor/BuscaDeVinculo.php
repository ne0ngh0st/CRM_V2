<?php

namespace App\Services\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Services\Cadastros\BuscaTitularidade;

/**
 * O que o modal da conta estratégica oferece para vincular: grupos do TOTVS e clientes.
 *
 * Cliente vem da busca de titularidade de Cadastros (`BuscaTitularidade`), que já resolve
 * nome/CNPJ/código pelo caminho rápido medido lá — reescrever aqui seria uma segunda cópia
 * da mesma armadilha de performance (Regra de ouro nº 8).
 */
class BuscaDeVinculo
{
    private const LIMITE_GRUPOS = 15;

    public function __construct(private readonly BuscaTitularidade $titularidade)
    {
    }

    /**
     * @return array{grupos: list<array>, clientes: list<array>}
     */
    public function buscar(string $termo): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < BuscaTitularidade::MINIMO_CARACTERES) {
            return ['grupos' => [], 'clientes' => []];
        }

        return [
            'grupos' => $this->grupos($termo),
            'clientes' => collect($this->titularidade->buscar($termo))
                ->where('tipo', 'cliente')
                ->unique('codCliente')
                ->map(fn (array $c) => [
                    'codigo' => $c['codCliente'],
                    'nome' => $c['nomeFantasia'] ?: $c['razaoSocial'],
                    'razaoSocial' => $c['razaoSocial'],
                    'cnpj' => $c['cnpj'],
                    'responsaveis' => $c['responsaveis'] ?? [],
                ])
                ->values()
                ->all(),
        ];
    }

    /** Prefixo primeiro, "contém" só se o prefixo não achar nada — mesma ordem da titularidade. */
    private function grupos(string $termo): array
    {
        $base = fn () => GrupoCliente::query()
            ->whereNotIn('codigo', ContaEstrategicaVinculo::GRUPOS_PROIBIDOS)
            ->orderBy('nome')
            ->limit(self::LIMITE_GRUPOS);

        $grupos = $base()->where(fn ($q) => $q->where('nome', 'like', "{$termo}%")->orWhere('codigo', $termo))->get();

        if ($grupos->isEmpty()) {
            $grupos = $base()->where('nome', 'like', "%{$termo}%")->get();
        }

        // Quantas lojas cada grupo traria — é o que evita vincular o grupo errado às cegas.
        $lojas = Cliente::query()
            ->whereIn('cod_grupo', $grupos->pluck('codigo'))
            ->groupBy('cod_grupo')
            ->selectRaw('cod_grupo, COUNT(*) as lojas')
            ->pluck('lojas', 'cod_grupo');

        return $grupos->map(fn (GrupoCliente $g) => [
            'codigo' => $g->codigo,
            'nome' => $g->nome,
            'lojas' => (int) ($lojas[$g->codigo] ?? 0),
        ])->all();
    }
}
