<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

final class PasswordService
{
    private const CHARSET_SIN_AMBIGUOS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz';
    private const LONGITUD_TEMPORAL = 8;

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verificar(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function generarTemporal(): string
    {
        $charset = self::CHARSET_SIN_AMBIGUOS;
        $maxIndex = strlen($charset) - 1;
        $resultado = '';

        for ($i = 0; $i < self::LONGITUD_TEMPORAL; $i++) {
            $resultado .= $charset[random_int(0, $maxIndex)];
        }

        if (!preg_match('/[A-Z]/', $resultado) || !preg_match('/[a-z]/', $resultado) || !preg_match('/[0-9]/', $resultado)) {
            return $this->generarTemporal();
        }

        return $resultado;
    }

    public function validarFortaleza(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }
        return true;
    }
}
