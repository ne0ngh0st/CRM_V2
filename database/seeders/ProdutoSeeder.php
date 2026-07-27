<?php

namespace Database\Seeders;

use App\Models\Produto;
use Illuminate\Database\Seeder;

class ProdutoSeeder extends Seeder
{
    /** @var list<array{cod: string, desc: string, cat: string, un: string, preco: float}> */
    private const PRODUTOS = [
        ['cod' => 'BOB001', 'desc' => 'Bobina Térmica 80x40', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 48.90],
        ['cod' => 'BOB002', 'desc' => 'Bobina Térmica 80x30', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 39.50],
        ['cod' => 'BOB003', 'desc' => 'Bobina Térmica 57x40', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 32.00],
        ['cod' => 'BOB004', 'desc' => 'Bobina Térmica 57x20', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 24.80],
        ['cod' => 'BOB005', 'desc' => 'Bobina Térmica 112x40', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 72.00],
        ['cod' => 'ETQ010', 'desc' => 'Etiqueta Adesiva 100x50', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 85.00],
        ['cod' => 'ETQ011', 'desc' => 'Etiqueta Adesiva 50x30', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 45.00],
        ['cod' => 'ETQ012', 'desc' => 'Etiqueta Adesiva 33x22', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 38.50],
        ['cod' => 'ETQ013', 'desc' => 'Etiqueta Couche 100x70', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 92.00],
        ['cod' => 'ETQ014', 'desc' => 'Etiqueta BOPP 60x40', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 110.00],
        ['cod' => 'TCK005', 'desc' => 'Termoticket 57mm', 'cat' => 'TERMOTICKETS', 'un' => 'RL', 'preco' => 55.00],
        ['cod' => 'TCK006', 'desc' => 'Termoticket 80mm', 'cat' => 'TERMOTICKETS', 'un' => 'RL', 'preco' => 68.00],
        ['cod' => 'TCK007', 'desc' => 'Termoticket 40mm', 'cat' => 'TERMOTICKETS', 'un' => 'RL', 'preco' => 42.00],
        ['cod' => 'A4-075', 'desc' => 'Papel A4 75g', 'cat' => 'PAPEL A4', 'un' => 'RX', 'preco' => 28.90],
        ['cod' => 'A4-090', 'desc' => 'Papel A4 90g', 'cat' => 'PAPEL A4', 'un' => 'RX', 'preco' => 34.50],
        ['cod' => 'A4-120', 'desc' => 'Papel A4 120g', 'cat' => 'PAPEL A4', 'un' => 'RX', 'preco' => 48.00],
        ['cod' => 'RFID02', 'desc' => 'Tag RFID UHF', 'cat' => 'TAGS RFID', 'un' => 'UN', 'preco' => 3.80],
        ['cod' => 'RFID03', 'desc' => 'Tag RFID HF', 'cat' => 'TAGS RFID', 'un' => 'UN', 'preco' => 4.20],
        ['cod' => 'RFID04', 'desc' => 'Label RFID UHF 100x50', 'cat' => 'TAGS RFID', 'un' => 'CX', 'preco' => 320.00],
        ['cod' => 'LCR001', 'desc' => 'Lacre Numerado 20cm', 'cat' => 'LACRES', 'un' => 'CX', 'preco' => 65.00],
        ['cod' => 'LCR002', 'desc' => 'Lacre Numerado 30cm', 'cat' => 'LACRES', 'un' => 'CX', 'preco' => 78.00],
        ['cod' => 'TAG001', 'desc' => 'Tag Cartão PVC 86x54', 'cat' => 'TAGS', 'un' => 'CX', 'preco' => 95.00],
        ['cod' => 'TAG002', 'desc' => 'Bag Tag 200x60', 'cat' => 'TAGS', 'un' => 'CX', 'preco' => 140.00],
        ['cod' => 'ROT001', 'desc' => 'Rótulo Garrafa 80x60', 'cat' => 'ROTULOS', 'un' => 'CX', 'preco' => 88.00],
        ['cod' => 'ROT002', 'desc' => 'Rótulo Frasco 50x30', 'cat' => 'ROTULOS', 'un' => 'CX', 'preco' => 62.00],
        ['cod' => 'V1001', 'desc' => 'Bobina Especial V-Line 80x40', 'cat' => 'BOBINAS TERMICAS', 'un' => 'RL', 'preco' => 52.00],
        ['cod' => 'V1002', 'desc' => 'Etiqueta Especial V-Line 100x50', 'cat' => 'ETIQUETAS', 'un' => 'CX', 'preco' => 98.00],
        ['cod' => 'SEM001', 'desc' => 'Produto sem preço cadastrado', 'cat' => 'SEM CATEGORIA', 'un' => 'UN', 'preco' => 0],
        ['cod' => 'SEM002', 'desc' => 'Amostra técnica sem tabela', 'cat' => 'SEM CATEGORIA', 'un' => null, 'preco' => null],
    ];

    public function run(): void
    {
        $agora = now();

        foreach (self::PRODUTOS as $p) {
            Produto::query()->updateOrCreate(
                ['cod_produto' => $p['cod']],
                [
                    'descricao' => $p['desc'],
                    'categoria' => $p['cat'],
                    'unidade' => $p['un'],
                    'preco_tabela' => $p['preco'],
                    'updated_at' => $agora,
                ],
            );
        }
    }
}
