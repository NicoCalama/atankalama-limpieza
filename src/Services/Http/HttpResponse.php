<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Services\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $cuerpo,
        public readonly ?string $errorRed = null,
    ) {
    }

    public function esExito(): bool
    {
        return $this->errorRed === null && $this->status >= 200 && $this->status < 300;
    }

    public function esReintentable(): bool
    {
        if ($this->errorRed !== null) {
            return true;
        }
        return $this->status >= 500 || $this->status === 408 || $this->status === 429;
    }

    public function json(): array
    {
        if ($this->cuerpo === '') {
            return [];
        }
        $decoded = json_decode($this->cuerpo, true);
        return is_array($decoded) ? $decoded : [];
    }
}
