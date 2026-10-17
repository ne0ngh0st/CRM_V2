<?php

namespace App\Services\VisaoDiretor\Redes;

use App\Models\Cliente;
use App\Services\Receita\SituacaoCadastral;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Facades\DB;

/**
 * Como cada cliente e lead ligado a uma rede aparece: nome, CNPJ, filiais, responsável e
 * última compra. Uma definição só para a página Maiores redes (linha aberta e contadores
 * da rede) e para o Excel de revisão (Regra de ouro nº 8).
 */
class DetalheDosVinculos
{
    public function __construct(private readonly NomeVendedorResolver $nomeVendedor)
    {
    }

    /**
     * O vendedor da rede é o que atende MAIS clientes dela; havendo outros, "+N" diz
     * quantos (o nome de cada um está na aba Vínculos).
     *
     * @param  array<string, int>  $contagem  vendedor → clientes
     */
    public function vendedorDaRede(array $contagem): ?string
    {
        if ($contagem === []) {
            return null;
        }

        arsort($contagem);
        $outros = count($contagem) - 1;

        return array_key_first($contagem).($outros > 0 ? " (+{$outros})" : '');
    }

    /**
     * Uma linha por código: a filial-âncora (a primeira que não é endereço de entrega) dá
     * nome e CNPJ; o responsável é o vendedor com mais filiais do código.
     *
     * `filiais_irregulares`: lojas (não endereço de entrega) com o CNPJ conhecido e não
     * ATIVO na Receita (`SituacaoCadastral::irregular()`), pelo `cnpj_situacoes`.
     *
     * @param  list<string>  $codigos
     * @return array<string, array{cliente_id: int, nome: string, fantasia: string, documento: ?string, filiais: int, filiais_irregulares: int, responsavel: string, ultima_compra: ?string}>
     */
    public function clientes(array $codigos): array
    {
        $porCodigo = [];

        foreach (array_chunk($codigos, 1000) as $lote) {
            $filiais = DB::table('clientes')
                ->leftJoin('cnpj_situacoes', 'cnpj_situacoes.cnpj', '=', 'clientes.cnpj_digitos')
                ->whereIn('clientes.cod_cliente', $lote)
                ->orderBy('clientes.cod_cliente')->orderBy('clientes.loja')
                ->get(['clientes.id', 'clientes.cod_cliente', 'clientes.loja', 'clientes.razao_social', 'clientes.nome_fantasia', 'clientes.cnpj',
                    'clientes.cod_vendedor', 'clientes.data_ultima_compra', 'cnpj_situacoes.situacao']);

            foreach ($filiais as $f) {
                $codigo = (string) $f->cod_cliente;
                $entrega = in_array(mb_substr((string) $f->loja, 0, 1), Cliente::PREFIXOS_ENTREGA, true);
                $atual = $porCodigo[$codigo] ?? null;

                if ($atual === null || ($atual['entrega'] && ! $entrega)) {
                    $porCodigo[$codigo] = [
                        'cliente_id' => (int) $f->id,
                        'nome' => (string) $f->razao_social,
                        'fantasia' => (string) $f->nome_fantasia,
                        'documento' => $f->cnpj,
                        'entrega' => $entrega,
                        'filiais' => $atual['filiais'] ?? 0,
                        'filiais_irregulares' => $atual['filiais_irregulares'] ?? 0,
                        'vendedores' => $atual['vendedores'] ?? [],
                        'ultima_compra' => $atual['ultima_compra'] ?? null,
                    ];
                }

                // Endereço de entrega não é loja (mesma régua da Carteira: "N filiais · M entregas").
                $porCodigo[$codigo]['filiais'] += $entrega ? 0 : 1;
                $porCodigo[$codigo]['filiais_irregulares'] += (int) (! $entrega && SituacaoCadastral::irregular($f->situacao));
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
                // Filial-âncora: o link da ficha do cliente.
                'cliente_id' => $c['cliente_id'],
                'nome' => $c['nome'],
                'fantasia' => $c['fantasia'],
                'documento' => $c['documento'],
                'filiais' => $c['filiais'],
                'filiais_irregulares' => $c['filiais_irregulares'],
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
    public function leads(array $ids): array
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
