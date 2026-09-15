<?php

/**
 * Gera os ícones quadrados do PWA a partir da logo oficial branca.
 *
 * A `autopel-logo-white.png` é o wordmark largo (Autopel + soluções), o mesmo da
 * navbar. Não existe marca quadrada no repositório — inventar um A geométrico
 * (a primeira versão deste script) ficava "bonito" e errado: não era a Autopel.
 *
 * O ícone é a logo branca centrada no navy da paleta (`#0F3A69`), com folga nas
 * bordas. Dois recortes, de propósito: o Android recorta ícone `maskable` em
 * círculo e comeria o A e o L se a logo fosse de borda a borda.
 *
 * Uso: docker compose exec app php scripts/gerar-icones-pwa.php
 */

if (! function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD não está habilitado — precisa da extensão gd.\n");
    exit(1);
}

$raiz = dirname(__DIR__);
$logoPath = $raiz.'/public/images/autopel-logo-white.png';
$destino = $raiz.'/public/images/pwa';

if (! is_file($logoPath)) {
    fwrite(STDERR, "Logo não encontrada: {$logoPath}\n");
    exit(1);
}

if (! is_dir($destino) && ! mkdir($destino, 0755, true) && ! is_dir($destino)) {
    fwrite(STDERR, "Não criou {$destino}\n");
    exit(1);
}

$navyHex = [0x0F, 0x3A, 0x69];

function recortarOpaco(GdImage $src): GdImage
{
    $largura = imagesx($src);
    $altura = imagesy($src);
    $minX = $largura;
    $minY = $altura;
    $maxX = 0;
    $maxY = 0;

    for ($x = 0; $x < $largura; $x++) {
        for ($y = 0; $y < $altura; $y++) {
            $alpha = (imagecolorat($src, $x, $y) >> 24) & 0x7F;

            if ($alpha >= 120) {
                continue;
            }

            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }

    if ($maxX < $minX) {
        return $src;
    }

    $w = $maxX - $minX + 1;
    $h = $maxY - $minY + 1;
    $corte = imagecreatetruecolor($w, $h);
    imagealphablending($corte, false);
    imagesavealpha($corte, true);
    $trans = imagecolorallocatealpha($corte, 0, 0, 0, 127);
    imagefill($corte, 0, 0, $trans);
    imagealphablending($corte, true);
    imagecopy($corte, $src, 0, 0, $minX, $minY, $w, $h);

    return $corte;
}

function montarIcone(GdImage $logo, int $lado, float $ocupacao): GdImage
{
    global $navyHex;

    $canvas = imagecreatetruecolor($lado, $lado);
    imagealphablending($canvas, false);
    $navy = imagecolorallocate($canvas, $navyHex[0], $navyHex[1], $navyHex[2]);
    imagefilledrectangle($canvas, 0, 0, $lado - 1, $lado - 1, $navy);
    imagealphablending($canvas, true);

    $logoW = imagesx($logo);
    $logoH = imagesy($logo);
    $maxW = (int) round($lado * $ocupacao);
    $maxH = (int) round($lado * 0.52);
    $escala = min($maxW / $logoW, $maxH / $logoH);
    $w = max(1, (int) round($logoW * $escala));
    $h = max(1, (int) round($logoH * $escala));
    $x = (int) round(($lado - $w) / 2);
    $y = (int) round(($lado - $h) / 2);

    imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $w, $h, $logoW, $logoH);

    return $canvas;
}

function gravar(GdImage $img, string $caminho): void
{
    if (! imagepng($img, $caminho, 6)) {
        fwrite(STDERR, "Falhou ao gravar {$caminho}\n");
        exit(1);
    }

    imagedestroy($img);
}

$logo = recortarOpaco(imagecreatefrompng($logoPath));

/*
 * `any` (home do iOS, favicon, splash): a logo pode ir maior.
 * `maskable` (Android recorta em círculo): folga de ~20% de cada lado, senão
 * o A e o L saem do recorte. São arquivos diferentes de propósito.
 */
gravar(montarIcone($logo, 512, 0.82), "{$destino}/icon-512.png");
gravar(montarIcone($logo, 512, 0.62), "{$destino}/icon-512-maskable.png");
gravar(montarIcone($logo, 192, 0.82), "{$destino}/icon-192.png");
gravar(montarIcone($logo, 180, 0.82), "{$destino}/apple-touch-icon.png");
gravar(montarIcone($logo, 32, 0.88), "{$destino}/favicon-32.png");

imagedestroy($logo);

echo "Ícones em {$destino}\n";
