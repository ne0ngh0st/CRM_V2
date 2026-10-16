<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\Cliente;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        private readonly NomeVendedorResolver $nomeVendedor,
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

        $clientes = $this->clientes(array_keys($r['clientes']));
        $leads = $this->leads(array_keys($r['leads']));
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
                'vendedor' => $this->vendedorDaRede($n['vendedores'] ?? $n['vendedores_lead'] ?? []),
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
            $detalhe = $lead ? ($this->leads([(int) $codigo])[(int) $codigo] ?? []) : ($this->clientes([$codigo])[$codigo] ?? []);

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

    /**
     * O vendedor da rede é o que atende MAIS clientes dela; havendo outros, "+N" diz
     * quantos (o nome de cada um está na aba Vínculos).
     *
     * @param  array<string, int>  $contagem  vendedor → clientes
     */
    private function vendedorDaRede(array $contagem): ?string
    {
        if ($contagem === []) {
            return null;
        }

        arsort($contagem);
        $outros = count($contagem) - 1;

        return array_key_first($contagem).($outros > 0 ? " (+{$outros})" : '');
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

    /**
     * Uma linha por código: a filial-âncora (a primeira que não é endereço de entrega) dá
     * nome e CNPJ; o responsável é o vendedor com mais filiais do código.
     *
     * @param  list<string>  $codigos
     * @return array<string, array{nome: string, fantasia: string, documento: ?string, filiais: int, responsavel: string, ultima_compra: ?string}>
     */
    private function clientes(array $codigos): array
    {
        $porCodigo = [];

        foreach (array_chunk($codigos, 1000) as $lote) {
            $filiais = DB::table('clientes')
                ->whereIn('cod_cliente', $lote)
                ->orderBy('cod_cliente')->orderBy('loja')
                ->get(['cod_cliente', 'loja', 'razao_social', 'nome_fantasia', 'cnpj', 'cod_vendedor', 'data_ultima_compra']);

            foreach ($filiais as $f) {
                $codigo = (string) $f->cod_cliente;
                $entrega = in_array(mb_substr((string) $f->loja, 0, 1), Cliente::PREFIXOS_ENTREGA, true);
                $atual = $porCodigo[$codigo] ?? null;

                if ($atual === null || ($atual['entrega'] && ! $entrega)) {
                    $porCodigo[$codigo] = [
                        'nome' => (string) $f->razao_social,
                        'fantasia' => (string) $f->nome_fantasia,
                        'documento' => $f->cnpj,
                        'entrega' => $entrega,
                        'filiais' => $atual['filiais'] ?? 0,
                        'vendedores' => $atual['vendedores'] ?? [],
                        'ultima_compra' => $atual['ultima_compra'] ?? null,
                    ];
                }

                // Endereço de entrega não é loja (mesma régua da Carteira: "N filiais · M entregas").
                $porCodigo[$codigo]['filiais'] += $entrega ? 0 : 1;
                $porCodigo[$codigo]['vendedores'][(string) $f->cod_vendedor] = ($porCodigo[$codigo]['vendedores'][(string) $f->cod_vendedor] ?? 0) + 1;
                if ($f->data_ultima_compra && ($porCodigo[$codigo]['ultima_compra'] === null || $f->data_ultima_compra > $porCodigo[$codigo]['ultima_compra'])) {
                    $porCodigo[$codigo]['ultima_compra'] = $f->data_ultima_compra;
                }
            }
        }

        $principal = [];
        foreach ($porCodigo as $codigo => $c) {
            arsort($c['vendedores']);
            $principal[$codigo] = (string) array_key_first($c['vendedores']);
        }
        $nomes = $this->nomeVendedor->porCodigo(array_values($principal));

        $saida = [];
        foreach ($porCodigo as $codigo => $c) {
            $saida[$codigo] = [
                'nome' => $c['nome'],
                'fantasia' => $c['fantasia'],
                'documento' => $c['documento'],
                'filiais' => $c['filiais'],
                'responsavel' => $nomes[$principal[$codigo]] ?? $principal[$codigo],
                'ultima_compra' => $c['ultima_compra'] ? substr((string) $c['ultima_compra'], 0, 10) : null,
            ];
        }

        return $saida;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{nome: string, fantasia: string, documento: ?string, responsavel: string}>
     */
    private function leads(array $ids): array
    {
        $saida = [];

        foreach (array_chunk($ids, 1000) as $lote) {
            $leads = DB::table('leads')
                ->leftJoin('users', 'users.id', '=', 'leads.user_id')
                ->whereIn('leads.id', $lote)
                ->get(['leads.id', 'leads.nome', 'leads.razao_social', 'leads.nome_fantasia', 'leads.cnpj', 'leads.cod_vendedor', 'users.display_name', 'users.name']);

            $nomes = $this->nomeVendedor->porCodigo($leads->pluck('cod_vendedor')->filter()->unique()->values());

            foreach ($leads as $l) {
                $saida[(int) $l->id] = [
                    'nome' => (string) ($l->razao_social ?: $l->nome),
                    'fantasia' => (string) ($l->nome_fantasia ?: ''),
                    'documento' => $l->cnpj,
                    // Quem atende é o vendedor do código, não o dono do lead (diretoria, 2026-10-07).
                    'responsavel' => $l->cod_vendedor
                        ? ($nomes[$l->cod_vendedor] ?? $l->cod_vendedor)
                        : ($l->display_name ?: ($l->name ?: 'Sem vendedor')),
                ];
            }
        }

        return $saida;
    }
}
