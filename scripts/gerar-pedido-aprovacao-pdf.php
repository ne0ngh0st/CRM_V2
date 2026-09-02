<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$destino = base_path('docs/pedido-aprovacao-claude-forge.pdf');

$pdf = Barryvdh\DomPDF\Facade\Pdf::loadView('internos.pedido-aprovacao-ferramentas')
    ->setPaper('a4');

$pdf->save($destino);

$bin = file_get_contents($destino);
$paginas = preg_match_all('/\/Type\s*\/Page[^s]/', $bin);

echo 'OK '.$destino.PHP_EOL;
echo 'bytes '.filesize($destino).PHP_EOL;
echo 'assinatura '.(str_starts_with($bin, '%PDF-') ? 'valida' : 'INVALIDA').PHP_EOL;
echo 'paginas '.$paginas.PHP_EOL;
