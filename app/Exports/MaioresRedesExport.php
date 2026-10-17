<?php

namespace App\Exports;

use App\Models\RedeMercado;
use App\Services\VisaoDiretor\Redes\CategoriasDeRede;
use App\Services\VisaoDiretor\Redes\MaioresRedes;
use App\Services\VisaoDiretor\Redes\MaioresRedesResolver;
use App\Services\VisaoDiretor\Redes\RelatorioDeRedes;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel da página Visão Diretor → Maiores redes: o recorte da tela (aba e filtros; no
 * Resumo, todas as categorias). Aba "Redes" com os números da tabela e aba "Clientes e
 * leads" com o que a linha aberta mostra de cada rede.
 *
 * As redes saem de `MaioresRedesResolver::consulta()` (a mesma query da lista) e os
 * vínculos de `MaioresRedes::vinculosDasRedes()` (o mesmo da linha aberta): a planilha
 * nunca recalcula nada. Cabeçalho e linha são listas paralelas; há teste conferindo.
 */
class MaioresRedesExport implements WithMultipleSheets
{
    /** Mesmos mapas do front (`constants/visaoDiretor.js`). Mudou lá, muda aqui. */
    private const ROTULOS_STATUS = [
        RedeMercado::STATUS_ATIVO => 'Ativo',
        RedeMercado::STATUS_A_TRABALHAR => 'A trabalhar',
    ];

    private const ROTULOS_NIVEL = [
        'nome' => 'Pelo nome',
        'grupo' => 'Grupo do TOTVS',
        'mesma_empresa' => 'Mesma empresa (CNPJ)',
        'manual' => 'Vinculado à mão',
    ];

    public const CABECALHO_REDES = [
        'Categoria', 'Posição na planilha', 'Rede', 'Linha da planilha', 'UF', 'Lojas no mercado', 'Qualidade do nº',
        'Base', 'Fonte', 'Clientes (códigos)', 'Filiais nossas', 'Clientes ativos', 'Leads', 'Vendedor', 'Última compra', 'Status',
        'CNPJ da rede', 'Razão social (Receita)', 'Situação na Receita', 'Situação desde', 'Confiança do CNPJ',
        'Lojas ativas da empresa (Receita)', 'Filiais nossas com CNPJ irregular',
    ];

    public const CABECALHO_VINCULOS = [
        'Categoria', 'Rede', 'Tipo', 'Código', 'Razão social', 'Nome fantasia', 'CNPJ', 'Filiais',
        'Responsável', 'Última compra', 'Status', 'Como vinculou', 'Filiais com CNPJ irregular',
    ];

    /**
     * Guarda só o recorte: as linhas são montadas em `sheets()`, quando o arquivo é gerado.
     *
     * @param  array{categoria: string, status: string, uf: string, busca: string, receita: string}  $filtros
     */
    public function __construct(private readonly array $filtros)
    {
    }

    public function sheets(): array
    {
        $redes = app(MaioresRedesResolver::class)->consulta($this->filtros)->get();
        $vinculos = app(MaioresRedes::class)->vinculosDasRedes($redes->modelKeys());
        $situacoes = app(MaioresRedesResolver::class)->situacoesDas($redes);

        $linhasRedes = [];
        $linhasVinculos = [];

        foreach ($redes as $r) {
            $linhasRedes[] = $this->linhaRede($r, $situacoes[$r->cnpj] ?? null);

            foreach ($vinculos[$r->id]['clientes'] as $c) {
                $linhasVinculos[] = $this->linhaVinculo($r, 'Cliente', $c['codigo'], $c);
            }
            foreach ($vinculos[$r->id]['leads'] as $l) {
                $linhasVinculos[] = $this->linhaVinculo($r, 'Lead', (string) $l['id'], $l + ['filiais' => null, 'ultima_compra' => null]);
            }
        }

        return [
            new AbaSimples('Redes', self::CABECALHO_REDES, $linhasRedes),
            new AbaSimples('Clientes e leads', self::CABECALHO_VINCULOS, $linhasVinculos),
        ];
    }

    /** @param  array{situacao: string, data: ?string}|null  $situacao */
    public function linhaRede(RedeMercado $r, ?array $situacao = null): array
    {
        // Mesma leitura da tela, para os dois dizerem a mesma coisa.
        $receita = MaioresRedesResolver::receita($r, $situacao);

        return [
            CategoriasDeRede::rotulo($r->categoria),
            $r->posicao,
            $r->nome,
            $r->linha_original,
            $r->uf ?? '',
            $r->lojas ?? '',
            RelatorioDeRedes::QUALIDADES[$r->qualidade] ?? $r->qualidade,
            $r->data_base ?? '',
            $r->fonte ?? '',
            $r->clientes,
            $r->filiais,
            $r->clientes_ativos,
            $r->leads,
            $r->vendedor ?? '',
            $r->ultima_compra?->format('d/m/Y') ?? '',
            self::ROTULOS_STATUS[$r->status] ?? $r->status,
            $receita['cnpj'] ?? '',
            $receita['cnpjRazaoSocial'] ?? '',
            $receita['cnpjSituacao'] ?? '',
            $receita['cnpjSituacaoData'] ? Carbon::parse($receita['cnpjSituacaoData'])->format('d/m/Y') : '',
            $receita['cnpjConfiancaRotulo'] ?? '',
            $receita['cnpjLojasAtivas'] ?? '',
            $receita['filiaisCnpjIrregular'],
        ];
    }

    /** @param  array<string, mixed>  $d  uma linha de `vinculosDasRedes()` */
    public function linhaVinculo(RedeMercado $r, string $tipo, string $codigo, array $d): array
    {
        return [
            CategoriasDeRede::rotulo($r->categoria),
            $r->nome,
            $tipo,
            $codigo,
            $d['nome'],
            $d['fantasia'] ?? '',
            $d['documento'] ?? '',
            $d['filiais'] ?? '',
            $d['responsavel'] ?? '',
            $d['ultima_compra'] ? Carbon::parse($d['ultima_compra'])->format('d/m/Y') : '',
            self::ROTULOS_STATUS[$d['status']] ?? $d['status'],
            self::ROTULOS_NIVEL[$d['nivel']] ?? $d['nivel'],
            $d['filiais_irregulares'] ?? '',
        ];
    }
}
