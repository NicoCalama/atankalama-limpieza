<?php

declare(strict_types=1);

/**
 * Recálculo de alertas predictivas (trabajador_en_riesgo, fin_turno_pendientes).
 *
 * Uso:
 *   php scripts/recalcular-alertas.php
 *   php scripts/recalcular-alertas.php --fecha=2026-04-15 --hora=14:30
 *
 * Pensado para cron cada `recalculo_intervalo_minutos` (default 15 min).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Services\AlertasPredictivasService;

Config::load(dirname(__DIR__));

$opts = getopt('', ['fecha::', 'hora::']);
$fecha = is_string($opts['fecha'] ?? null) && $opts['fecha'] !== '' ? $opts['fecha'] : null;
$hora = is_string($opts['hora'] ?? null) && $opts['hora'] !== '' ? $opts['hora'] : null;

$svc = new AlertasPredictivasService();
$stats = $svc->recalcularTodos($fecha, $hora);

echo "Recálculo completado:\n";
foreach ($stats as $k => $v) {
    echo "  {$k}: {$v}\n";
}
