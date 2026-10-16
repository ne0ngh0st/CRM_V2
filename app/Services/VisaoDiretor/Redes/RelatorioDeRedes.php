<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Services\Carteira\ClienteStatusResolver;
use Illuminate\Support\Carbon;

/**
 * Monta as abas do Excel de revisão dos vínculos (`visao-diretor:redes-vinculos`), a partir
 * do JSON das redes e do resultado do `VinculoDeRedes`. Só lê.
 *
 * Status (decisão do Tony, 2026-10-16): só **Ativo** e **A trabalhar**. Cliente é Ativo
 * pela última compra MAIS RECENTE entre as filiais do código (a pill da Carteira agrupada);
 * lead, perdendo e inativo são A trabalhar. A rede é Ativa se pelo menos um cliente
 * ligado a ela está ativo.
 */
class RelatorioDeRedes
{
    public const ATIVO = 'Ativo';

    public const A_TRABALHAR = 'A trabalhar';

    private const NIVEIS = [
        VinculoDeRedes::NOME => 'Nome',
        VinculoDeRedes::GRUPO => 'Grupo do TOTVS',
        VinculoDeRedes::MESMA_EMPRESA => 'Mesma empresa (CNPJ)',
    ];

    private const QUALIDADES = [
        'atualizado' => 'Atualizado',
        'pesquisado' => 'Pesquisado (out/26)',
        'estimativa' => 'Estimativa',
        'planilha_anterior' => 'Planilha anterior',
        'sem_numero' => 'Sem número público',
    ];

    public function __construct(
        private readonly VinculoDeRedes $vinculo,
        private readonly ClienteStatusResolver $status,
        private readonly DetalheDosVinculos $detalhe,
    ) {
    }

    /**
     * @param  array{redes: list<array>, puladas: list<array>}  $json
     * @return array{redes: list<array>, vinculos: list<array>, conflitos: list<array>, sem_vinculo: list<array>, limpeza: list<array>, resumo: array<string, int>}
     */
    public function montar(array $json): array
    {
        $redes = $json['redes'];
        $r = $this->vinculo->calcular($redes);

        $clientes = $this->detalhe->clientes(array_keys($r['clientes']));
        $leads = $this->detalhe->leads(array_keys($r['leads']));
        $limiteAtivo = $this->status->limiteAtivo();

        $porRede = [];
        $vinculos = [];

        foreach ($r['clientes'] as $codigo => $v) {
            $c = $clientes[$codigo] ?? null;
            if ($c === null) {
                continue;
            }

            $ativo = $c['ultima_compra'] && Carbon::parse($c['ultima_compra'])->gte($limiteAtivo);
            $porRede[$v['rede']]['clientes'] = ($porRede[$v['rede']]['clientes'] ?? 0) + 1;
            $porRede[$v['rede']]['filiais'] = ($porRede[$v['rede']]['filiais'] ?? 0) + $c['filiais'];
            $porRede[$v['rede']]['ativos'] = ($porRede[$v['rede']]['ativos'] ?? 0) + ($ativo ? 1 : 0);
            $porRede[$v['rede']]['vendedores'][$c['responsavel']] = ($porRede[$v['rede']]['vendedores'][$c['responsavel']] ?? 0) + 1;
            if ($c['ultima_compra'] && $c['ultima_compra'] > ($porRede[$v['rede']]['ultima_compra'] ?? '')) {
                $porRede[$v['rede']]['ultima_compra'] = $c['ultima_compra'];
            }

            $vinculos[] = $this->linhaVinculo($redes[$v['rede']], 'Cliente', (string) $codigo, $c + [
                'status' => $ativo ? self::ATIVO : self::A_TRABALHAR,
            ], $v['nivel']);
        }

        foreach ($r['leads'] as $id => $v) {
            $l = $leads[$id] ?? null;
            if ($l === null) {
                continue;
            }

            $porRede[$v['rede']]['leads'] = ($porRede[$v['rede']]['leads'] ?? 0) + 1;
            $porRede[$v['rede']]['vendedores_lead'][$l['responsavel']] = ($porRede[$v['rede']]['vendedores_lead'][$l['responsavel']] ?? 0) + 1;
            $vinculos[] = $this->linhaVinculo($redes[$v['rede']], 'Lead', (string) $id, $l + [
                'filiais' => null,
                'ultima_compra' => null,
                'status' => self::A_TRABALHAR,
            ], $v['nivel']);
        }

        usort($vinculos, fn ($a, $b) => [$a['posicao'], $a['rede'], $a['tipo'], $a['nome']] <=> [$b['posicao'], $b['rede'], $b['tipo'], $b['nome']]);

        $ambigua = [];
        foreach ($r['ambiguas'] as $a) {
            $ambigua[$a['rede']][] = $a['tipo'] === 'lead' ? 'leads' : 'clientes';
        }

        $linhasRedes = [];
        $semVinculo = [];
        foreach ($redes as $i => $rede) {
            $n = $porRede[$i] ?? [];
            $linha = [
                'posicao' => $rede['posicao'],
                'categoria' => CategoriasDeRede::rotulo($rede['categoria']),
                'categoria_chave' => $rede['categoria'],
                'data_base' => $rede['data_base'] ?? null,
                'rede' => $rede['nome'],
                'apelidos' => implode(', ', $rede['apelidos'] ?? []),
                'linha_original' => $rede['linha_original'],
                'uf' => $rede['uf'],
                'lojas' => $rede['lojas'],
                'qualidade' => self::QUALIDADES[$rede['qualidade']] ?? $rede['qualidade'],
                'fonte' => $rede['fonte'],
                'clientes' => $n['clientes'] ?? 0,
                'filiais' => $n['filiais'] ?? 0,
                'clientes_ativos' => $n['ativos'] ?? 0,
                'leads' => $n['leads'] ?? 0,
                'status' => ($n['ativos'] ?? 0) > 0 ? self::ATIVO : self::A_TRABALHAR,
                'ambigua' => implode(', ', $ambigua[$i] ?? []),
                // Sem cliente, o vendedor do lead; rede sem nada fica sem vendedor.
                'vendedor' => $this->detalhe->vendedorDaRede($n['vendedores'] ?? $n['vendedores_lead'] ?? []),
                'ultima_compra' => $n['ultima_compra'] ?? null,
            ];
            $linhasRedes[] = $linha;

            if ($linha['clientes'] === 0 && $linha['leads'] === 0) {
                $semVinculo[] = $linha;
            }
        }

        $conflitos = [];
        foreach ($r['conflitos'] as $c) {
            $lead = $c['tipo'] === 'lead';
            $codigo = $lead ? substr($c['codigo'], 1) : $c['codigo'];
            $detalhe = $lead ? ($this->detalhe->leads([(int) $codigo])[(int) $codigo] ?? []) : ($this->detalhe->clientes([$codigo])[$codigo] ?? []);

            $conflitos[] = [
                'tipo' => $lead ? 'Lead' : 'Cliente',
                'codigo' => $codigo,
                'nome' => $detalhe['nome'] ?? '',
                'fantasia' => $detalhe['fantasia'] ?? '',
                'nivel' => self::NIVEIS[$c['nivel']] ?? $c['nivel'],
                'redes' => implode(' | ', array_map(fn ($i) => $redes[$i]['nome'].' ('.CategoriasDeRede::rotulo($redes[$i]['categoria']).')', $c['redes'])),
                'situacao' => $c['decidido'] === null
                    ? 'Fora de todas (empate)'
                    : 'Ficou com '.$redes[$c['decidido']]['nome'].' ('.$c['criterio'].')',
            ];
        }

        $limpeza = [];
        foreach ($redes as $rede) {
            $limpeza[$rede['posicao']] ??= ['posicao' => $rede['posicao'], 'linha_original' => $rede['linha_original'], 'virou' => [], 'motivo' => ''];
            $limpeza[$rede['posicao']]['virou'][] = $rede['nome'];
        }
        foreach ($json['puladas'] ?? [] as $p) {
            $limpeza[$p['posicao']] = ['posicao' => $p['posicao'], 'linha_original' => $p['linha_original'], 'virou' => [], 'motivo' => $p['motivo']];
        }
        $limpeza = array_values(array_filter($limpeza, fn ($l) => $l['virou'] !== [$l['linha_original']]));
        usort($limpeza, fn ($a, $b) => $a['posicao'] <=> $b['posicao']);

        return [
            'redes' => $linhasRedes,
            'vinculos' => $vinculos,
            'conflitos' => $conflitos,
            'sem_vinculo' => $semVinculo,
            'limpeza' => array_map(fn ($l) => $l + ['virou_texto' => implode(' | ', $l['virou'])], $limpeza),
            'resumo' => [
                'redes' => count($redes),
                'com_cliente' => count(array_filter($linhasRedes, fn ($l) => $l['clientes'] > 0)),
                'ativas' => count(array_filter($linhasRedes, fn ($l) => $l['status'] === self::ATIVO)),
                'clientes' => count($r['clientes']),
                'leads' => count($r['leads']),
                'conflitos' => count(array_filter($r['conflitos'], fn ($c) => $c['decidido'] === null)),
                'desempates' => count(array_filter($r['conflitos'], fn ($c) => $c['decidido'] !== null)),
                'sem_vinculo' => count($semVinculo),
            ],
        ];
    }

    private function linhaVinculo(array $rede, string $tipo, string $codigo, array $d, string $nivel): array
    {
        return [
            'posicao' => $rede['posicao'],
            'categoria' => CategoriasDeRede::rotulo($rede['categoria']),
            'rede' => $rede['nome'],
            'tipo' => $tipo,
            'codigo' => $codigo,
            'nome' => $d['nome'],
            'fantasia' => $d['fantasia'],
            'documento' => $d['documento'],
            'filiais' => $d['filiais'],
            'responsavel' => $d['responsavel'],
            'ultima_compra' => $d['ultima_compra'],
            'status' => $d['status'],
            'nivel' => self::NIVEIS[$nivel] ?? $nivel,
        ];
    }

}
