<?php
/**
 * Avatar circular con inicial y color determinístico.
 *
 * Uso: <?= avatarHtml($nombre, $rut, $tamano) ?>
 *   $nombre: nombre completo del usuario
 *   $rut: RUT (para color determinístico)
 *   $tamano: 'sm' (32px), 'md' (48px), 'lg' (56px). Default: 'md'
 */

function avatarHtml(string $nombre, string $rut, string $tamano = 'md'): string
{
    $colores = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-500', 'bg-indigo-500', 'bg-teal-500'];
    $colorIdx = abs(crc32($rut)) % count($colores);
    $color = $colores[$colorIdx];

    $inicial = mb_strtoupper(mb_substr(explode(' ', $nombre)[0], 0, 1));

    $clases = match ($tamano) {
        'sm' => 'w-8 h-8 text-xs',
        'lg' => 'w-14 h-14 md:w-14 md:h-14 text-xl',
        default => 'w-12 h-12 md:w-14 md:h-14 text-lg', // md
    };

    return '<div class="' . $clases . ' rounded-full ' . $color .
        ' flex items-center justify-center text-white font-bold flex-shrink-0">' .
        htmlspecialchars($inicial) . '</div>';
}
