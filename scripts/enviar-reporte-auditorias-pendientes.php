<?php

declare(strict_types=1);

/**
 * Envía a los administradores (rol Admin, activos, con email) el reporte diario de
 * habitaciones limpiadas y no auditadas al corte de las 23:50, separado por turno
 * mañana/tarde. Ver ReportesService::auditoriasPendientes() y
 * EmailService::enviarReporteAuditoriasPendientes().
 *
 * Pensado para cron cPanel diario a las 23:50 hora de Santiago:
 *   50 23 * * * /usr/local/bin/php -q /home4/cat6852/public_html/limpieza/app_core/scripts/enviar-reporte-auditorias-pendientes.php
 *
 * Uso manual (prueba, no muta nada — solo lee y envía correo):
 *   php scripts/enviar-reporte-auditorias-pendientes.php
 *   php scripts/enviar-reporte-auditorias-pendientes.php --fecha=2026-09-10
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Services\EmailService;
use Atankalama\Limpieza\Services\ReportesService;

Config::load(dirname(__DIR__));

$opts  = getopt('', ['fecha::']);
$fecha = is_string($opts['fecha'] ?? null) && $opts['fecha'] !== '' ? $opts['fecha'] : date('Y-m-d');

$reportes = new ReportesService();
$email    = new EmailService();

// 'ambos' hoteles: el correo consolida las dos propiedades en una sola tabla por
// turno (igual que el resto de reportes cuando no se filtra por hotel).
$reporte       = $reportes->auditoriasPendientes($fecha, 'ambos');
$destinatarios = $reportes->destinatariosAdminConEmail();

if ($destinatarios === []) {
    echo "Sin destinatarios (ningún Admin activo con email registrado). Nada que enviar.\n";
    exit(0);
}

$enviados = 0;
$fallidos = 0;
foreach ($destinatarios as $admin) {
    $ok = $email->enviarReporteAuditoriasPendientes(
        (string) $admin['email'],
        (string) $admin['nombre'],
        $fecha,
        $reporte['turnos']
    );
    if ($ok) {
        $enviados++;
    } else {
        $fallidos++;
        Logger::error('reportes', 'Fallo al enviar reporte de auditorías pendientes', [
            'destinatario' => $admin['email'],
            'fecha'        => $fecha,
        ]);
    }
}

$pendientesManana = count($reporte['turnos']['mañana']['pendientes']);
$pendientesTarde  = count($reporte['turnos']['tarde']['pendientes']);

echo "Reporte de auditorías pendientes — {$fecha}\n";
echo "  Turno mañana: {$reporte['turnos']['mañana']['total']} limpiadas, {$pendientesManana} sin auditar a tiempo\n";
echo "  Turno tarde:  {$reporte['turnos']['tarde']['total']} limpiadas, {$pendientesTarde} sin auditar a tiempo\n";
echo "  Correos enviados: {$enviados}\n";
echo "  Correos fallidos: {$fallidos}\n";

if ($fallidos > 0) {
    // Exit code distinto de 0: cPanel puede avisar por su propio correo de sistema
    // si el cron "falla" — visibilidad extra además del log, para que una falla de
    // SMTP no pase inadvertida un día entero.
    exit(1);
}
