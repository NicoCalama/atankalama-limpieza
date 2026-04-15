<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services;

use RuntimeException;

final class CloudbedsException extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct($mensaje);
    }
}
