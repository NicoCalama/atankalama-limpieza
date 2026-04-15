<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use RuntimeException;

final class HabitacionException extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $httpStatus,
    ) {
        parent::__construct($mensaje);
    }
}
