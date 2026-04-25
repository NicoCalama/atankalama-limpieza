<?php
/**
 * Genera los íconos PNG de la PWA (192x192 y 512x512).
 * Requiere ext-gd.
 * Uso: php scripts/generate-icons.php
 */

$sizes = [192, 512];
$outDir = __DIR__ . '/../public/assets/img';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);

    // Fondo azul redondeado simulado (GD no hace border-radius, usamos fondo sólido)
    $bg    = imagecolorallocate($img, 37, 99, 235);   // blue-600 #2563eb
    $white = imagecolorallocate($img, 255, 255, 255);

    imagefill($img, 0, 0, $bg);

    // Letra "A" centrada
    $margin = (int) ($size * 0.2);
    $fontSize = (int) ($size * 0.45);

    // Usar fuente built-in de GD (no requiere TTF)
    $font = 5; // mayor fuente built-in (8×16 px)
    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);

    // Escalar: GD built-in fonts son muy pequeñas, usamos imagestring
    // Para íconos grandes, dibujamos un rectángulo blanco con "A" grande
    $rectSize  = (int) ($size * 0.55);
    $rectX     = (int) (($size - $rectSize) / 2);
    $rectY     = (int) (($size - $rectSize) / 2);

    // Rectángulo blanco redondeado (aproximado con elipse + rectángulos)
    $r = (int) ($rectSize * 0.18);
    imagefilledrectangle($img, $rectX + $r, $rectY, $rectX + $rectSize - $r, $rectY + $rectSize, $white);
    imagefilledrectangle($img, $rectX, $rectY + $r, $rectX + $rectSize, $rectY + $rectSize - $r, $white);
    imagefilledellipse($img, $rectX + $r,             $rectY + $r,             $r * 2, $r * 2, $white);
    imagefilledellipse($img, $rectX + $rectSize - $r, $rectY + $r,             $r * 2, $r * 2, $white);
    imagefilledellipse($img, $rectX + $r,             $rectY + $rectSize - $r, $r * 2, $r * 2, $white);
    imagefilledellipse($img, $rectX + $rectSize - $r, $rectY + $rectSize - $r, $r * 2, $r * 2, $white);

    // "A" azul centrada en el rectángulo blanco
    $blue = imagecolorallocate($img, 37, 99, 235);
    $textX = $rectX + (int)(($rectSize - $charW) / 2);
    $textY = $rectY + (int)(($rectSize - $charH) / 2);
    imagestring($img, $font, $textX, $textY, 'A', $blue);

    $path = "{$outDir}/icon-{$size}.png";
    imagepng($img, $path);
    imagedestroy($img);
    echo "  [OK] {$path}\n";
}

echo "Íconos generados.\n";
