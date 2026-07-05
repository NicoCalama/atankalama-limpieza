<?php

declare(strict_types=1);

/**
 * Stub de entrada de PRODUCCIÓN — public_html/limpieza/index.php
 *
 * Todo el código vive en ./app_core (denegado por web con Require all denied);
 * este stub solo delega en el front controller real. Los paths internos de la
 * app se anclan con __DIR__ al ubicación física de app_core, así que no hay
 * nada más que configurar acá. El prefijo de URL lo aporta BASE_PATH en
 * app_core/.env (patrón de deploy de Maisterchef, ver docs/deploy-cpanel.md).
 */
require __DIR__ . '/app_core/public/index.php';
