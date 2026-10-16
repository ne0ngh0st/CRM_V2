<?php

namespace App\Exports;

use App\Services\VisaoDiretor\Redes\CategoriasDeRede;
use App\Services\VisaoDiretor\Redes\RelatorioDeRedes;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Primeira aba do Excel das redes: o MESMO layout da aba "POR SEGMENTO 2026" da planilha
 * da diretoria (um bloco por segmento, lado a lado, título navy e cabeçalho azul-claro),
 * com três colunas a mais em cada bloco: vendedor, última compra e status.
 *
 * Diferença deliberada: a planilha mostrava só as 50 maiores; aqui vão TODAS as redes do
 * segmento (as bandeiras separadas e sem número de lojas vêm no fim do bloco).
 */
class RedesPorSegmentoSheet implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const CABECALHO = ['EMPRESA', 'UF', 'LOJAS', 'BASE', 'VENDEDOR', 'ÚLTIMA COMPRA', 'STATUS'];

    /** Larguras da planilha original nas 4 primeiras; as novas no mesmo espírito. */
    private const LARGURAS = [42.6, 5.6, 8.6, 9.6, 30, 14, 12, 2.6];

    private const NAVY = 'FF1F3A5A';

    private const AZUL_CLARO = 'FFDDEBF7';

    /** @var array<string, list<array>> categoria → redes */
    private array $blocos = [];

    public function __construct(array $redes)
    {
        foreach (array_keys(CategoriasDeRede::CATEGORIAS) as $categoria) {
            $this->blocos[$categoria] = [];
        }
        foreach ($redes as $r) {
            $this->blocos[$r['categoria_chave']][] = $r;
        }
        foreach ($this->blocos as &$lista) {
            usort($lista, fn ($a, $b) => [$b['lojas'] === null ? 0 : 1, $b['lojas'] ?? 0, -$b['posicao']]
                <=> [$a['lojas'] === null ? 0 : 1, $a['lojas'] ?? 0, -$a['posicao']]);
        }
    }

    public function title(): string
    {
        return 'POR SEGMENTO 2026';
    }

    public function array(): array
    {
        $largura = count(self::CABECALHO) + 1;
        $altura = max(array_map('count', $this->blocos)) + 2;
        $grade = array_fill(0, $altura, array_fill(0, $largura * count($this->blocos), null));

        $b = 0;
        foreach ($this->blocos as $categoria => $redes) {
            $col = $b * $largura;
            $grade[0][$col] = mb_strtoupper(CategoriasDeRede::rotulo($categoria));
            foreach (self::CABECALHO as $i => $titulo) {
                $grade[1][$col + $i] = $titulo;
            }
            foreach ($redes as $j => $r) {
                $grade[$j + 2][$col] = $r['rede'];
                $grade[$j + 2][$col + 1] = $r['uf'];
                $grade[$j + 2][$col + 2] = $r['lojas'];
                $grade[$j + 2][$col + 3] = $r['data_base'];
                $grade[$j + 2][$col + 4] = $r['vendedor'];
                $grade[$j + 2][$col + 5] = $r['ultima_compra'] ? Carbon::parse($r['ultima_compra'])->format('d/m/Y') : null;
                $grade[$j + 2][$col + 6] = $r['status'];
            }
            $b++;
        }

        return $grade;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $evento) {
            $sheet = $evento->sheet->getDelegate();
            $largura = count(self::CABECALHO) + 1;
            $fina = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]]];

            $b = 0;
            foreach ($this->blocos as $redes) {
                $ini = $b * $largura + 1;
                $fim = $ini + count(self::CABECALHO) - 1;
                $l = fn (int $c) => Coordinate::stringFromColumnIndex($c);

                $sheet->mergeCells("{$l($ini)}1:{$l($fim)}1");
                $sheet->getStyle("{$l($ini)}1:{$l($fim)}1")->applyFromArray($fina + [
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::NAVY]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle("{$l($ini)}2:{$l($fim)}2")->applyFromArray($fina + [
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL_CLARO]],
                ]);

                $ultima = count($redes) + 2;
                if ($ultima >= 3) {
                    $sheet->getStyle("{$l($ini)}3:{$l($fim)}{$ultima}")->applyFromArray($fina);
                    $sheet->getStyle("{$l($ini + 2)}3:{$l($ini + 2)}{$ultima}")->getNumberFormat()->setFormatCode('#,##0');

                    foreach ($redes as $j => $r) {
                        // BASE é texto ("2026", "dez/25"): sem isto "2026" vira número.
                        if ($r['data_base'] !== null) {
                            $sheet->getCell($l($ini + 3).($j + 3))->setValueExplicit((string) $r['data_base'], DataType::TYPE_STRING);
                        }
                        $verde = $r['status'] === RelatorioDeRedes::ATIVO;
                        $sheet->getStyle($l($fim).($j + 3))->getFont()
                            ->setBold($verde)
                            ->getColor()->setARGB($verde ? 'FF2E7D32' : 'FF9A5B00');
                    }
                }

                foreach (self::LARGURAS as $i => $w) {
                    $sheet->getColumnDimension($l($ini + $i))->setWidth($w);
                }
                $b++;
            }

            $sheet->freezePane('A3');
        }];
    }
}
