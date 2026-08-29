<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use Atankalama\Limpieza\Core\Config;
use GdImage;

/**
 * Procesa fotos subidas por el usuario (tickets) y las deja listas para servir:
 * redimensionadas a máx. 30x40cm @72dpi y convertidas a WebP <150KB.
 *
 * Backend-first a propósito: el resize/compresión real ocurre acá, no en el navegador,
 * para no depender de que cada dispositivo/browser lo haga igual (ver docs/tickets.md).
 */
final class ImagenAdjuntoService
{
    /** Nunca se procesa un archivo original más pesado que esto (defensa contra memoria/DoS). */
    private const MAX_BYTES_ORIGINAL = 12 * 1024 * 1024;

    /** Objetivo de peso de salida. */
    private const MAX_BYTES_SALIDA = 150 * 1024;

    // 30x40cm @72dpi ≈ 850x1134px. Lado largo/corto en vez de ancho/alto fijos:
    // así respeta la orientación real de la foto (vertical u horizontal).
    private const LADO_LARGO_PX = 1134;
    private const LADO_CORTO_PX = 850;

    private const CALIDAD_INICIAL = 82;
    private const CALIDAD_MINIMA = 35;
    private const CALIDAD_PASO = 10;

    /**
     * @return array{ruta: string, tamano_bytes: int, ancho: int, alto: int}
     */
    public function guardarComoWebp(string $tmpPath, int $tamanoOriginalBytes, string $subcarpeta = 'tickets'): array
    {
        if ($tamanoOriginalBytes <= 0 || $tamanoOriginalBytes > self::MAX_BYTES_ORIGINAL) {
            $maxMb = (int) (self::MAX_BYTES_ORIGINAL / 1024 / 1024);
            throw new ImagenException('ARCHIVO_MUY_GRANDE', "La foto no puede superar {$maxMb} MB.");
        }
        if (!is_file($tmpPath)) {
            throw new ImagenException('ARCHIVO_INVALIDO', 'No se pudo leer el archivo subido.');
        }

        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new ImagenException('FORMATO_INVALIDO', 'El archivo no es una imagen válida.');
        }
        [$anchoOriginal, $altoOriginal, $tipo] = $info;

        $imagen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
            default => null,
        };
        if (!$imagen instanceof GdImage) {
            throw new ImagenException('FORMATO_INVALIDO', 'Formato de imagen no soportado. Usa JPG, PNG o WEBP.');
        }

        // Fotos de celular: la orientación real suele venir en el tag EXIF, no en los
        // píxeles (GD no la aplica solo). Sin esto, muchas fotos quedan "acostadas".
        if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmpPath);
            $orientacion = (int) ($exif['Orientation'] ?? 1);
            $imagen = $this->aplicarOrientacionExif($imagen, $orientacion);
            if (in_array($orientacion, [5, 6, 7, 8], true)) {
                [$anchoOriginal, $altoOriginal] = [$altoOriginal, $anchoOriginal];
            }
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($imagen);
        }
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        [$anchoFinal, $altoFinal] = $this->calcularDimensiones($anchoOriginal, $altoOriginal);
        if ($anchoFinal !== $anchoOriginal || $altoFinal !== $altoOriginal) {
            $redimensionada = imagecreatetruecolor($anchoFinal, $altoFinal);
            imagealphablending($redimensionada, false);
            imagesavealpha($redimensionada, true);
            imagecopyresampled($redimensionada, $imagen, 0, 0, 0, 0, $anchoFinal, $altoFinal, $anchoOriginal, $altoOriginal);
            imagedestroy($imagen);
            $imagen = $redimensionada;
        }

        $destino = $this->rutaDestino($subcarpeta);
        $dir = dirname($destino['absoluta']);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($imagen);
            throw new ImagenException('ERROR_GUARDADO', 'No se pudo preparar la carpeta de destino.', 500);
        }

        $peso = 0;
        $calidad = self::CALIDAD_INICIAL;
        do {
            imagewebp($imagen, $destino['absoluta'], $calidad);
            $peso = filesize($destino['absoluta']) ?: 0;
            $calidad -= self::CALIDAD_PASO;
        } while ($peso > self::MAX_BYTES_SALIDA && $calidad >= self::CALIDAD_MINIMA);

        imagedestroy($imagen);

        if ($peso === 0 || !is_file($destino['absoluta'])) {
            throw new ImagenException('ERROR_GUARDADO', 'No se pudo guardar la imagen procesada.', 500);
        }

        return [
            'ruta' => $destino['relativa'],
            'tamano_bytes' => $peso,
            'ancho' => $anchoFinal,
            'alto' => $altoFinal,
        ];
    }

    /** Solo cubre las rotaciones puras (3/6/8) — los volteos (2/4/5/7) son casos raros de EXIF. */
    private function aplicarOrientacionExif(GdImage $imagen, int $orientacion): GdImage
    {
        $rotada = match ($orientacion) {
            3 => imagerotate($imagen, 180, 0),
            6 => imagerotate($imagen, -90, 0),
            8 => imagerotate($imagen, 90, 0),
            default => $imagen,
        };
        if ($rotada instanceof GdImage && $rotada !== $imagen) {
            imagedestroy($imagen);
            return $rotada;
        }
        return $imagen;
    }

    /** @return array{0: int, 1: int} */
    private function calcularDimensiones(int $ancho, int $alto): array
    {
        $ladoLargo = max($ancho, $alto);
        $ladoCorto = max(1, min($ancho, $alto));
        // Nunca agranda: si la foto ya es más chica que el máximo, escala = 1.
        $escala = min(1.0, self::LADO_LARGO_PX / $ladoLargo, self::LADO_CORTO_PX / $ladoCorto);

        return [
            max(1, (int) round($ancho * $escala)),
            max(1, (int) round($alto * $escala)),
        ];
    }

    /** @return array{relativa: string, absoluta: string} */
    private function rutaDestino(string $subcarpeta): array
    {
        $nombre = bin2hex(random_bytes(8)) . '.webp';
        $relativa = $subcarpeta . '/' . date('Y') . '/' . date('m') . '/' . $nombre;
        $absoluta = Config::basePath() . '/public/uploads/' . $relativa;

        return ['relativa' => $relativa, 'absoluta' => $absoluta];
    }
}
