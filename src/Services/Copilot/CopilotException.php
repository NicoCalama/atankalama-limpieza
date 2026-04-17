<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Copilot;

final class CopilotException extends \RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($mensaje);
    }
}
