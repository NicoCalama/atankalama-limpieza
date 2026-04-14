<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Helpers;

final class Rut
{
    public static function normalizar(string $input): string
    {
        $limpio = preg_replace('/[\s\.]/', '', $input) ?? '';
        $limpio = strtoupper($limpio);

        if (!str_contains($limpio, '-') && strlen($limpio) >= 2) {
            $numero = substr($limpio, 0, -1);
            $dv = substr($limpio, -1);
            $limpio = $numero . '-' . $dv;
        }

        return $limpio;
    }

    public static function validar(string $rut): bool
    {
        $normalizado = self::normalizar($rut);

        if (!preg_match('/^\d{1,8}-[\dK]$/', $normalizado)) {
            return false;
        }

        [$numero, $dvEsperado] = explode('-', $normalizado);
        $dvCalculado = self::calcularDigitoVerificador($numero);

        return $dvCalculado === $dvEsperado;
    }

    public static function calcularDigitoVerificador(string $numero): string
    {
        $suma = 0;
        $multiplicador = 2;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += ((int) $numero[$i]) * $multiplicador;
            $multiplicador = $multiplicador === 7 ? 2 : $multiplicador + 1;
        }

        $resto = $suma % 11;
        $resultado = 11 - $resto;

        return match (true) {
            $resultado === 11 => '0',
            $resultado === 10 => 'K',
            default => (string) $resultado,
        };
    }
}
