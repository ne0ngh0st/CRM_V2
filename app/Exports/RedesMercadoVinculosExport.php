<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel de revisão dos vínculos das redes do mercado (`visao-diretor:redes-vinculos`), para
 * o Tony conferir ANTES de a página existir. As linhas vêm de `RelatorioDeRedes::montar()`.
 */
class RedesMercadoVinculosExport implements WithMultipleSheets
{
    public function __construct(private readonly array $relatorio)
    {
    }

    public function sheets(): array
    {
        $r = $this->relatorio;
        $data = fn (?string $d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';

        return [
            new RedesPorSegmentoSheet($r['redes']),

            $this->aba('Redes', [
                'Posição', 'Categoria', 'Rede', 'Apelidos de busca', 'Linha da planilha', 'UF', 'Lojas no mercado',
                'Qualidade do nº', 'Fonte', 'Clientes (códigos)', 'Filiais nossas', 'Clientes ativos', 'Leads', 'Status', 'Ambígua em',
            ], array_map(fn ($l) => [
                $l['posicao'], $l['categoria'], $l['rede'], $l['apelidos'], $l['linha_original'], $l['uf'] ?? '', $l['lojas'] ?? '',
                $l['qualidade'], $l['fonte'] ?? '', $l['clientes'], $l['filiais'], $l['clientes_ativos'], $l['leads'], $l['status'], $l['ambigua'],
            ], $r['redes'])),

            $this->aba('Vínculos', [
                'Posição', 'Categoria', 'Rede', 'Tipo', 'Código', 'Razão social', 'Nome fantasia', 'CNPJ',
                'Filiais', 'Responsável', 'Última compra', 'Status', 'Como vinculou',
            ], array_map(fn ($l) => [
                $l['posicao'], $l['categoria'], $l['rede'], $l['tipo'], $l['codigo'], $l['nome'], $l['fantasia'], $l['documento'] ?? '',
                $l['filiais'] ?? '', $l['responsavel'], $data($l['ultima_compra']), $l['status'], $l['nivel'],
            ], $r['vinculos'])),

            $this->aba('Conflitos', [
                'Tipo', 'Código', 'Razão social', 'Nome fantasia', 'Como casou', 'Redes que disputam', 'Situação',
            ], array_map(fn ($l) => [
                $l['tipo'], $l['codigo'], $l['nome'], $l['fantasia'], $l['nivel'], $l['redes'], $l['situacao'],
            ], $r['conflitos'])),

            $this->aba('Sem vínculo', [
                'Posição', 'Categoria', 'Rede', 'Apelidos de busca', 'UF', 'Lojas no mercado', 'Ambígua em',
            ], array_map(fn ($l) => [
                $l['posicao'], $l['categoria'], $l['rede'], $l['apelidos'], $l['uf'] ?? '', $l['lojas'] ?? '', $l['ambigua'],
            ], $r['sem_vinculo'])),

            $this->aba('Limpeza', [
                'Posição', 'Linha da planilha', 'Virou', 'Motivo (quando saiu)',
            ], array_map(fn ($l) => [
                $l['posicao'], $l['linha_original'], $l['virou_texto'], $l['motivo'],
            ], $r['limpeza'])),
        ];
    }

    private function aba(string $titulo, array $cabecalho, array $linhas): AbaSimples
    {
        return new AbaSimples($titulo, $cabecalho, $linhas);
    }
}
