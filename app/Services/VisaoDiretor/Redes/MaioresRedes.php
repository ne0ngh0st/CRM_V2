<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Services\Carteira\ClienteStatusResolver;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de ESCRITA da página Maiores redes: carga do JSON, reconstrução dos vínculos
 * do motor, vínculo manual e remoção pela tela, e os contadores de cada rede.
 *
 * - **Reconstrução** (`reconstruir()`) refaz só os vínculos do motor. `manual` e
 *   `recusado` vêm da tela e nunca são tocados — a rede não "desaprende" o que alguém
 *   corrigiu. Roda no fim do `totvs:atualizar` (clientes novos, última compra nova).
 * - **Contadores** (`recalcular()`) moram na linha da rede para a página não agregar nada
 *   ao vivo. Toda escrita aqui termina recalculando as redes que mexeu.
 */
class MaioresRedes
{
    public function __construct(
        private readonly VinculoDeRedes $vinculo,
        private readonly DetalheDosVinculos $detalhe,
        private readonly ClienteStatusResolver $status,
    ) {
    }

    /**
     * Carrega o JSON das redes. Idempotente pela chave (categoria, nome): rodar de novo
     * atualiza; rede que saiu do JSON fica `ativa = false` (some da tela, os vínculos
     * manuais dela ficam guardados).
     *
     * @return array{novas: int, atualizadas: int, arquivadas: int}
     */
    public function importar(array $json): array
    {
        return DB::transaction(function () use ($json) {
            $vistas = [];
            $novas = 0;
            $atualizadas = 0;

            foreach ($json['redes'] as $r) {
                $rede = RedeMercado::firstOrNew(['categoria' => $r['categoria'], 'nome' => mb_strtoupper($r['nome'])]);
                $rede->exists ? $atualizadas++ : $novas++;
                $rede->fill([
                    'posicao' => $r['posicao'],
                    'apelidos' => $r['apelidos'] ?: null,
                    'uf' => $r['uf'],
                    'lojas' => $r['lojas'],
                    'data_base' => $r['data_base'],
                    'qualidade' => $r['qualidade'],
                    'fonte' => $r['fonte'] ? mb_substr($r['fonte'], 0, 500) : null,
                    'linha_original' => mb_substr($r['linha_original'], 0, 255),
                    'ativa' => true,
                ])->save();
                $vistas[] = $rede->id;
            }

            $arquivadas = RedeMercado::whereNotIn('id', $vistas)->where('ativa', true)->update(['ativa' => false]);

            return ['novas' => $novas, 'atualizadas' => $atualizadas, 'arquivadas' => $arquivadas];
        });
    }

    /**
     * Refaz os vínculos do motor de todas as redes ativas e recalcula os contadores.
     *
     * @return array{clientes: int, leads: int, conflitos: int, ms: int}
     */
    public function reconstruir(): array
    {
        $inicio = microtime(true);
        $redes = RedeMercado::where('ativa', true)->orderBy('id')->get();
        $indice = $redes->pluck('id')->flip();

        $entrada = $redes->map(fn (RedeMercado $r) => [
            'nome' => $r->nome, 'apelidos' => $r->apelidos ?? [], 'categoria' => $r->categoria, 'uf' => $r->uf, 'lojas' => $r->lojas,
        ])->all();

        $fixos = [];
        $recusados = [];
        foreach (RedeMercadoVinculo::whereIn('rede_id', $redes->pluck('id'))->where(fn ($q) => $q->where('nivel', RedeMercadoVinculo::MANUAL)->orWhere('recusado', true))->get() as $v) {
            $chave = $v->tipo.':'.$v->codigo;
            if ($v->recusado) {
                $recusados[$indice[$v->rede_id].'|'.$chave] = true;
            } else {
                $fixos[$chave] = $indice[$v->rede_id];
            }
        }
        // Manual de rede ARQUIVADA também segura o código (ninguém mais o leva).
        foreach (RedeMercadoVinculo::where('nivel', RedeMercadoVinculo::MANUAL)->where('recusado', false)->whereNotIn('rede_id', $redes->pluck('id'))->get() as $v) {
            $fixos[$v->tipo.':'.$v->codigo] = -1;
        }

        $r = $this->vinculo->calcular($entrada, $fixos, $recusados);

        $linhas = [];
        $agora = now();
        foreach (['clientes' => RedeMercadoVinculo::CLIENTE, 'leads' => RedeMercadoVinculo::LEAD] as $chave => $tipo) {
            foreach ($r[$chave] as $codigo => $v) {
                $linhas[] = [
                    'rede_id' => $redes[$v['rede']]->id, 'tipo' => $tipo, 'codigo' => (string) $codigo, 'nivel' => $v['nivel'],
                    'recusado' => false, 'created_at' => $agora, 'updated_at' => $agora,
                ];
            }
        }

        DB::transaction(function () use ($linhas) {
            RedeMercadoVinculo::where('nivel', '!=', RedeMercadoVinculo::MANUAL)->where('recusado', false)->delete();
            foreach (array_chunk($linhas, 1000) as $lote) {
                RedeMercadoVinculo::insert($lote);
            }
            $this->recalcular();
        });

        return [
            'clientes' => count($r['clientes']),
            'leads' => count($r['leads']),
            'conflitos' => count(array_filter($r['conflitos'], fn ($c) => $c['decidido'] === null)),
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    }

    /**
     * Vincula à mão um cliente (`cod_cliente`) ou lead à rede. Se ele estava em outra rede,
     * sai de lá — cada cliente pertence a uma rede só. Vínculo manual não é desfeito pela
     * reconstrução.
     */
    public function vincular(RedeMercado $rede, string $tipo, string $codigo, ?int $userId): void
    {
        DB::transaction(function () use ($rede, $tipo, $codigo, $userId) {
            $antes = RedeMercadoVinculo::where('tipo', $tipo)->where('codigo', $codigo)->where('recusado', false)->first();

            if ($antes && $antes->rede_id !== $rede->id) {
                $antes->delete();
            }

            RedeMercadoVinculo::updateOrCreate(
                ['rede_id' => $rede->id, 'tipo' => $tipo, 'codigo' => $codigo],
                ['nivel' => RedeMercadoVinculo::MANUAL, 'recusado' => false, 'user_id' => $userId],
            );

            $this->recalcular(array_filter([$rede->id, $antes?->rede_id]));
        });
    }

    /** Tira o cliente/lead da rede e grava a recusa: o motor não o liga de novo AQUI. */
    public function remover(RedeMercado $rede, string $tipo, string $codigo, ?int $userId): void
    {
        DB::transaction(function () use ($rede, $tipo, $codigo, $userId) {
            RedeMercadoVinculo::updateOrCreate(
                ['rede_id' => $rede->id, 'tipo' => $tipo, 'codigo' => $codigo],
                ['recusado' => true, 'user_id' => $userId, 'nivel' => RedeMercadoVinculo::MANUAL],
            );

            $this->recalcular([$rede->id]);
        });
    }

    /**
     * Os clientes e leads vivos da rede, como a linha aberta da página os mostra.
     *
     * @return array{clientes: list<array>, leads: list<array>}
     */
    public function vinculosDa(RedeMercado $rede): array
    {
        $vivos = $rede->vinculos()->where('recusado', false)->get();
        $limite = $this->status->limiteAtivo()->toDateString();

        $nivelDoCliente = $vivos->where('tipo', RedeMercadoVinculo::CLIENTE)->pluck('nivel', 'codigo');
        $clientes = [];
        foreach ($this->detalhe->clientes($nivelDoCliente->keys()->map(fn ($c) => (string) $c)->all()) as $codigo => $c) {
            $clientes[] = $c + [
                'codigo' => (string) $codigo,
                'status' => $c['ultima_compra'] && $c['ultima_compra'] >= $limite ? RedeMercado::STATUS_ATIVO : RedeMercado::STATUS_A_TRABALHAR,
                'nivel' => $nivelDoCliente[$codigo],
            ];
        }
        // Ativos primeiro, depois a compra mais recente.
        usort($clientes, fn ($a, $b) => [$b['status'] === RedeMercado::STATUS_ATIVO, $b['ultima_compra'] ?? ''] <=> [$a['status'] === RedeMercado::STATUS_ATIVO, $a['ultima_compra'] ?? '']);

        $nivelDoLead = $vivos->where('tipo', RedeMercadoVinculo::LEAD)->pluck('nivel', 'codigo');
        $leads = [];
        foreach ($this->detalhe->leads($nivelDoLead->keys()->map(fn ($id) => (int) $id)->all()) as $id => $l) {
            $leads[] = $l + ['id' => $id, 'status' => RedeMercado::STATUS_A_TRABALHAR, 'nivel' => $nivelDoLead[(string) $id]];
        }

        return ['clientes' => $clientes, 'leads' => $leads];
    }

    /**
     * Recalcula os contadores das redes (todas, sem argumento). A rede é Ativa com pelo
     * menos um cliente ativo; o vendedor é o que atende mais clientes dela.
     *
     * @param  list<int>|null  $ids
     */
    public function recalcular(?array $ids = null): void
    {
        $vivos = RedeMercadoVinculo::query()
            ->where('recusado', false)
            ->when($ids !== null, fn ($q) => $q->whereIn('rede_id', $ids))
            ->get(['rede_id', 'tipo', 'codigo']);

        $clientes = $this->detalhe->clientes($vivos->where('tipo', RedeMercadoVinculo::CLIENTE)->pluck('codigo')->map(fn ($c) => (string) $c)->unique()->values()->all());
        $leads = $this->detalhe->leads($vivos->where('tipo', RedeMercadoVinculo::LEAD)->pluck('codigo')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $limite = $this->status->limiteAtivo()->toDateString();

        $porRede = [];
        foreach ($vivos as $v) {
            $n = &$porRede[$v->rede_id];
            if ($v->tipo === RedeMercadoVinculo::CLIENTE) {
                $c = $clientes[$v->codigo] ?? null;
                if ($c === null) {
                    continue;
                }
                $n['clientes'] = ($n['clientes'] ?? 0) + 1;
                $n['filiais'] = ($n['filiais'] ?? 0) + $c['filiais'];
                $n['ativos'] = ($n['ativos'] ?? 0) + (int) ($c['ultima_compra'] && $c['ultima_compra'] >= $limite);
                $n['vendedores'][$c['responsavel']] = ($n['vendedores'][$c['responsavel']] ?? 0) + 1;
                if ($c['ultima_compra'] && $c['ultima_compra'] > ($n['ultima_compra'] ?? '')) {
                    $n['ultima_compra'] = $c['ultima_compra'];
                }
            } elseif (isset($leads[(int) $v->codigo])) {
                $n['leads'] = ($n['leads'] ?? 0) + 1;
                $n['vendedores_lead'][$leads[(int) $v->codigo]['responsavel']] = ($n['vendedores_lead'][$leads[(int) $v->codigo]['responsavel']] ?? 0) + 1;
            }
            unset($n);
        }

        RedeMercado::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get()->each(function (RedeMercado $rede) use ($porRede) {
            $n = $porRede[$rede->id] ?? [];
            $rede->forceFill([
                'clientes' => $n['clientes'] ?? 0,
                'filiais' => $n['filiais'] ?? 0,
                'clientes_ativos' => $n['ativos'] ?? 0,
                'leads' => $n['leads'] ?? 0,
                'ultima_compra' => $n['ultima_compra'] ?? null,
                'vendedor' => $this->detalhe->vendedorDaRede($n['vendedores'] ?? $n['vendedores_lead'] ?? []),
                'status' => ($n['ativos'] ?? 0) > 0 ? RedeMercado::STATUS_ATIVO : RedeMercado::STATUS_A_TRABALHAR,
            ])->save();
        });
    }
}
