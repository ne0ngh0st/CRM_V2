<?php

namespace App\Exports;

use App\Models\CotacaoItem;
use App\Models\EstoqueRevenda;
use App\Services\Cotacao\CotacaoService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * A planilha de uma cotação — mesmas colunas e cores da que o Cotacao.exe gerava
 * (`planilha.py`), trocando as colunas "preencher decisão" pelo que já foi conferido
 * na tela.
 *
 * ⚠️ A coluna de custo (Ult.Preco do Relatório de Revenda) só existe para quem pode ver custo. É
 * decidido por quem monta o export (`CatalogoDeExportacoes`), não aqui.
 */
class CotacaoExport implements FromArray, WithHeadings, WithEvents
{
    /** Mesmas cores do `planilha.py`, por status. */
    private const CORES = [
        'APRENDIDO' => 'C6E0B4',
        'AUTOMATICO' => 'E2EFDA',
        'REVISAR' => 'FFF2CC',
        'TALVEZ' => 'FFE0C0',
        'SEM_MATCH' => 'FCE4E4',
        'SEM_MATCH_CONFIRMADO' => 'F2F2F2',
    ];

    /** @var list<string> status de cada linha, na ordem, para colorir no AfterSheet */
    private array $statusDasLinhas = [];

    /**
     * @param  Collection<int, CotacaoItem>  $itens
     * @param  Collection<string, EstoqueRevenda>  $estoque  indexado por código
     */
    public function __construct(
        private readonly Collection $itens,
        private readonly Collection $estoque,
        private readonly bool $comCusto,
    ) {}

    public function headings(): array
    {
        return array_values(array_filter([
            'Cód. cliente', 'Descrição do cliente', 'Subgrupo', 'Un', 'Qtd período',
            'Valor período', 'Ordens', 'Preço cliente', 'Situação', 'Score',
            'Nosso código', 'Nossa descrição', 'Marca', 'Disponível', 'Físico', 'Comprado',
            $this->comCusto ? 'Custo (últ. compra)' : null,
            'Estoque', 'Por que casou / por que não', 'Outro candidato', 'Outro candidato',
            'Conferido por', 'Conferido em',
        ]));
    }

    public function array(): array
    {
        $linhas = [];
        $this->statusDasLinhas = [];

        foreach ($this->itens as $item) {
            $codigo = $item->codigoFinal();
            $nosso = $codigo !== null ? ($this->estoque[$codigo] ?? null) : null;

            $alternativas = collect($item->alternativas ?? [])
                ->reject(fn ($a) => $a['codigo'] === $codigo)
                ->take(2)
                ->map(function ($a) {
                    $e = $this->estoque[$a['codigo']] ?? null;

                    return sprintf('[%d] %s | %s | disp %s', round($a['score']), $a['codigo'],
                        $e?->descricao ?? '—', $e ? number_format($e->disponivel, 0, ',', '.') : '—');
                })
                ->values();

            $linha = [
                $item->cod_cliente,
                $item->descricao_cliente,
                $item->subgrupo,
                $item->unidade,
                $item->qtd_periodo,
                $item->valor_periodo,
                $item->ordens,
                $item->preco_unit,
                CotacaoService::rotulo($item->status)['rotulo'],
                $item->score,
                $nosso ? $codigo : ($codigo ?? ''),
                $nosso?->descricao ?? '',
                $nosso?->marca ?? '',
                $nosso?->disponivel ?? '',
                $nosso?->fisico ?? '',
                $nosso?->comprado ?? '',
            ];

            if ($this->comCusto) {
                $linha[] = $nosso?->ult_preco ?? '';
            }

            $linha[] = $this->situacaoEstoque($nosso, $item->qtd_periodo);
            $linha[] = implode('; ', $item->motivos ?? []);
            $linha[] = $alternativas[0] ?? '';
            $linha[] = $alternativas[1] ?? '';
            $linha[] = $item->decisor ? ($item->decisor->display_name ?: $item->decisor->name) : '';
            $linha[] = $item->decidido_em?->format('d/m/Y H:i') ?? '';

            $linhas[] = $linha;
            $this->statusDasLinhas[] = $item->status;
        }

        return $linhas;
    }

    /** Igual ao `_situacao_estoque` do .exe. */
    private function situacaoEstoque(?EstoqueRevenda $e, float $qtd): string
    {
        if ($e === null) {
            return '';
        }

        if ($e->disponivel <= 0) {
            return 'SEM ESTOQUE'.($e->comprado > 0 ? ' (comprado)' : '');
        }

        if ($qtd > 0 && $e->disponivel < $qtd) {
            return sprintf('PARCIAL %d/%d', $e->disponivel, $qtd);
        }

        return 'DISPONÍVEL';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $evento) {
                $planilha = $evento->sheet->getDelegate();
                $ultimaColuna = $planilha->getHighestColumn();

                $planilha->getStyle("A1:{$ultimaColuna}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '44546A']],
                ]);
                $planilha->freezePane('C2');
                $planilha->setAutoFilter("A1:{$ultimaColuna}".(count($this->statusDasLinhas) + 1));

                foreach ($this->statusDasLinhas as $i => $status) {
                    $cor = self::CORES[$status] ?? null;
                    if ($cor !== null) {
                        $linha = $i + 2;
                        $planilha->getStyle("A{$linha}:{$ultimaColuna}{$linha}")
                            ->getFill()->setFillType('solid')->getStartColor()->setRGB($cor);
                    }
                }
            },
        ];
    }
}
