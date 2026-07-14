<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

/**
 * Utilidades de color para la apariencia configurable (Ajustes → Colores).
 *
 * La supervisora elige UN color base por estado/hotel; de él se derivan las
 * variantes claro/oscuro en servidor (sin depender de color-mix() del browser,
 * que los teléfonos viejos del equipo pueden no soportar). La receta imita la
 * paleta Tailwind usada hasta ahora (bg-*-100 / text-*-800 y dark bg-*-900/40 /
 * text-*-200).
 */
final class Colores
{
    /** Valida un color hex de 6 dígitos con #. */
    public static function validarHex(string $hex): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /**
     * Deriva las 4 variantes de un color base.
     *
     * @return array{bg: string, fg: string, bgDark: string, fgDark: string}
     */
    public static function variantes(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);

        return [
            // Modo claro: fondo pastel (85% blanco) + texto oscuro del mismo tono
            'bg' => self::hex(self::mezclar($r, 255, 0.85), self::mezclar($g, 255, 0.85), self::mezclar($b, 255, 0.85)),
            'fg' => self::hex((int) round($r * 0.57), (int) round($g * 0.57), (int) round($b * 0.57)),
            // Modo oscuro: fondo translúcido del tono + texto tintado claro
            'bgDark' => sprintf('rgba(%d, %d, %d, 0.28)', $r, $g, $b),
            'fgDark' => self::hex(self::mezclar($r, 255, 0.60), self::mezclar($g, 255, 0.60), self::mezclar($b, 255, 0.60)),
        ];
    }

    /**
     * Variantes de acento de tarjeta por hotel: borde sólido + tinte de fondo
     * sutil (claro y oscuro) + color de texto para la etiqueta del hotel.
     *
     * @return array{borde: string, tinte: string, tinteDark: string, texto: string, textoDark: string}
     */
    public static function variantesAcento(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);

        return [
            'borde' => $hex,
            'tinte' => sprintf('rgba(%d, %d, %d, 0.06)', $r, $g, $b),
            'tinteDark' => sprintf('rgba(%d, %d, %d, 0.10)', $r, $g, $b),
            'texto' => self::hex((int) round($r * 0.70), (int) round($g * 0.70), (int) round($b * 0.70)),
            'textoDark' => self::hex(self::mezclar($r, 255, 0.45), self::mezclar($g, 255, 0.45), self::mezclar($b, 255, 0.45)),
        ];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Mezcla un canal hacia otro: resultado = canal*(1-f) + destino*f. */
    private static function mezclar(int $canal, int $destino, float $f): int
    {
        return (int) round($canal * (1 - $f) + $destino * $f);
    }

    private static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
