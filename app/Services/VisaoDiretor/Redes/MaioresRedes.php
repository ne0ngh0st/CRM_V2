<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Receita\SituacaoCadastral;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto de ESCRITA da página Maiores redes: carga do JSON, reconstrução dos vínculos
 * do motor, vínculo manual e remoção pela tela, e os contadores de cada rede.
 *
 * - **Reconstrução** (`reconstruir()`) refaz só os vínculos do motor. `manual` e
 *   `recusado` vêm da tela e nunca são tocados — a rede não "desaprende" o que alguém
 *   corrigiu. Roda no fim do `totvs:atualizar` (clientes novos, última compra nova).
 * - **Contadores** (`recalcular()`) moram na linha da rede para a página não agregar nada
 *   ao vivo — inclusive o veredito `situacao_rede` (`SituacaoDaRede`). Toda escrita aqui
 *   termina recalculando as redes que mexeu.
 * - **CNPJ da rede** (`gravarCnpjs()`, da carga `visao-diretor:cnpj-das-redes`, e
 *   `informarCnpj()`, da tela). O informado à mão (`CnpjDasRedes::MANUAL`) a carga não toca.
 */
class MaioresRedes
{
    public function __construct(
        private readonly VinculoDeRedes $vinculo,
        private readonly DetalheDosVinculos $detalhe,
        private readonly ClienteStatusResolver $status,
        private readonly SituacaoCadastral $situacoes,
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
     * Grava o resultado da carga (`CnpjDasRedes::resultado()`) e a situação da matriz de
     * cada rede no `cnpj_situacoes` (a resposta única a "este CNPJ está ativo?"). Rede com
     * CNPJ informado à mão fica como está.
     *
     * @param  array<int, array{confianca: string, cnpj: ?string, razao_social: ?string, lojas_ativas: ?int, matriz: ?array, capital: ?string, porte: ?string}>  $resultado
     * @return array<string, int>  confiança => redes gravadas
     */
    public function gravarCnpjs(array $resultado, string $referencia): array
    {
        $contagem = [];
        $situacoes = [];

        DB::transaction(function () use ($resultado, $referencia, &$contagem, &$situacoes) {
            RedeMercado::whereIn('id', array_keys($resultado) ?: [0])->get()
                ->each(function (RedeMercado $rede) use ($resultado, $referencia, &$contagem, &$situacoes) {
                    $r = $resultado[$rede->id];
                    // As lojas com o nome no mercado valem também para a rede corrigida à mão.
                    $rede->forceFill(['cnpj_lojas_com_nome_ativas' => $r['lojas_com_nome_ativas']]);

                    if ($rede->cnpj_confianca !== CnpjDasRedes::MANUAL) {
                        $rede->forceFill([
                            'cnpj' => $r['cnpj'],
                            'cnpj_razao_social' => $r['razao_social'] ? mb_substr($r['razao_social'], 0, 150) : null,
                            'cnpj_confianca' => $r['confianca'],
                            'cnpj_lojas_ativas' => $r['lojas_ativas'],
                            'cnpj_referencia' => $referencia,
                            'cnpj_user_id' => null,
                        ]);
                        $contagem[$r['confianca']] = ($contagem[$r['confianca']] ?? 0) + 1;
                        if ($r['matriz'] !== null) {
                            $situacoes[$r['cnpj']] = [$r['matriz']['situacao'], $r['matriz']['data'], $r['matriz']['cnae'], $r['capital'], $r['porte']];
                        }
                    }

                    $rede->save();
                });

            $this->situacoes->registrarDaBase($situacoes, $referencia);
            $this->recalcular();
        });

        return $contagem;
    }

    /**
     * O CNPJ da rede à mão (correção pela tela). `null` = "esta rede não tem um CNPJ
     * único" (franquia, bandeira de outra empresa) — também é decisão, e a carga respeita.
     */
    public function informarCnpj(RedeMercado $rede, ?string $cnpj, ?string $razaoSocial, ?int $userId): void
    {
        $rede->forceFill([
            'cnpj' => $cnpj,
            'cnpj_razao_social' => $cnpj && $razaoSocial ? mb_substr($razaoSocial, 0, 150) : null,
            'cnpj_confianca' => CnpjDasRedes::MANUAL,
            'cnpj_lojas_ativas' => null,
            'cnpj_referencia' => null,
            'cnpj_user_id' => $userId,
        ])->save();

        $this->recalcular([$rede->id]);
    }

    /**
     * Os clientes e leads vivos da rede, como a linha aberta da página os mostra.
     *
     * @return array{clientes: list<array>, leads: list<array>}
     */
    public function vinculosDa(RedeMercado $rede): array
    {
        return $this->vinculosDasRedes([$rede->id])[$rede->id];
    }

    /**
     * Os clientes e leads vivos de várias redes de uma vez — a linha aberta (uma rede) e o
     * Excel (o recorte inteiro) saem daqui, com o mesmo número de consultas para 1 ou 900.
     *
     * @param  list<int>  $ids
     * @return array<int, array{clientes: list<array>, leads: list<array>}>  rede_id => vínculos
     */
    public function vinculosDasRedes(array $ids): array
    {
        $vivos = RedeMercadoVinculo::query()->where('recusado', false)->whereIn('rede_id', $ids ?: [0])
            ->get(['rede_id', 'tipo', 'codigo', 'nivel']);
        $limite = $this->status->limiteAtivo()->toDateString();

        $clientes = $this->detalhe->clientes($vivos->where('tipo', RedeMercadoVinculo::CLIENTE)->pluck('codigo')->map(fn ($c) => (string) $c)->unique()->values()->all());
        $leads = $this->detalhe->leads($vivos->where('tipo', RedeMercadoVinculo::LEAD)->pluck('codigo')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $saida = array_fill_keys($ids, ['clientes' => [], 'leads' => []]);
        foreach ($vivos as $v) {
            if ($v->tipo === RedeMercadoVinculo::CLIENTE) {
                $c = $clientes[(string) $v->codigo] ?? null;
                if ($c !== null) {
                    $saida[$v->rede_id]['clientes'][] = $c + [
                        'codigo' => (string) $v->codigo,
                        'status' => $c['ultima_compra'] && $c['ultima_compra'] >= $limite ? RedeMercado::STATUS_ATIVO : RedeMercado::STATUS_A_TRABALHAR,
                        'nivel' => $v->nivel,
                    ];
                }
            } elseif (isset($leads[(int) $v->codigo])) {
                $saida[$v->rede_id]['leads'][] = $leads[(int) $v->codigo] + ['id' => (int) $v->codigo, 'status' => RedeMercado::STATUS_A_TRABALHAR, 'nivel' => $v->nivel];
            }
        }

        foreach ($saida as &$rede) {
            // Ativos primeiro, depois a compra mais recente.
            usort($rede['clientes'], fn ($a, $b) => [$b['status'] === RedeMercado::STATUS_ATIVO, $b['ultima_compra'] ?? ''] <=> [$a['status'] === RedeMercado::STATUS_ATIVO, $a['ultima_compra'] ?? '']);
        }
        unset($rede);

        return $saida;
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
                $n['irregulares'] = ($n['irregulares'] ?? 0) + $c['filiais_irregulares'];
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

        $redes = RedeMercado::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
        $situacaoDoCnpj = $this->situacoes->situacoes($redes->pluck('cnpj')->filter()->all());

        $redes->each(function (RedeMercado $rede) use ($porRede, $situacaoDoCnpj) {
            $n = $porRede[$rede->id] ?? [];
            $rede->forceFill([
                'situacao_rede' => SituacaoDaRede::de($rede->cnpj, $situacaoDoCnpj[$rede->cnpj] ?? null, $rede->cnpj_lojas_com_nome_ativas),
                'clientes' => $n['clientes'] ?? 0,
                'filiais' => $n['filiais'] ?? 0,
                'filiais_cnpj_irregular' => $n['irregulares'] ?? 0,
                'clientes_ativos' => $n['ativos'] ?? 0,
                'leads' => $n['leads'] ?? 0,
                'ultima_compra' => $n['ultima_compra'] ?? null,
                'vendedor' => $this->detalhe->vendedorDaRede($n['vendedores'] ?? $n['vendedores_lead'] ?? []),
                'status' => ($n['ativos'] ?? 0) > 0 ? RedeMercado::STATUS_ATIVO : RedeMercado::STATUS_A_TRABALHAR,
            ])->save();
        });
    }
}
