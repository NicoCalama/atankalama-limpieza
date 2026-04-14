<?php

declare(strict_types=1);

namespace Atankalama\Limpieza\Middleware;

use Atankalama\Limpieza\Core\Request;
use Atankalama\Limpieza\Core\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
