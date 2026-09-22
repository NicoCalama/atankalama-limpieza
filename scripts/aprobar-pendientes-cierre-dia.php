<?php

declare(strict_types=1);

/**
 * Cierre de día automático (23:55): aprueba las habitaciones que quedaron
 * completada_pendiente_auditoria sin que un supervisor las auditara a tiempo.
 *
 * Motivo (ver conversación 2026-09-15): Cloudbeds vuelve a marcar 'dirty' las
 * habitaciones ocupadas en la madrugada. CloudbedsSyncService solo revierte a
 * 'sucia' las piezas en estado TERMINAL (aprobada/aprobada_con_observacion/
 * rechazada) — una pieza completada_pendiente_auditoria queda "colgada" auditando
 * una limpieza de ayer mientras la pieza ya está sucia de nuevo hoy. Este cron,
 * 5 minutos después del reporte de las 23:50 (enviar-reporte-auditorias-pendientes.php),
 * cierra esas piezas a Habitacion::ESTADO_APROBADA_AUTOMATICA (estado terminal
 * distinto de una auditoría real: queda registrado en `auditorias` con veredicto
 * 'aprobado_automatico' y auditor_id del usuario "Sistema", visible en el
 * historial de la pieza). También empuja 'clean' a Cloudbeds, igual que una
 * aprobación manual real (AuditoriaService::emitirVeredicto()).
 *
 * Requiere haber corrido antes scripts/migrate-estado-aprobada-automatica.php
 * (estado/veredicto nuevos + usuario "Sistema").
 *
 * Pensado para cron cPanel diario a las 23:55 hora de Santiago:
 *   55 23 * * * /usr/local/bin/php -q /home4/cat6852/public_html/limpieza/app_core/scripts/aprobar-pendientes-cierre-dia.php
 *
 * Uso manual:
 *   php scripts/aprobar-pendientes-cierre-dia.php --dry-run   # solo lista, no muta nada
 *   php scripts/aprobar-pendientes-cierre-dia.php
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\AuditoriaException;
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;

Config::load(dirname(__DIR__));

$opts   = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $opts);

$sistemaId = Database::fetchOne("SELECT id FROM #__usuarios WHERE rut = ?", ['SISTEMA-CRON']);
if ($sistemaId === null) {
    fwrite(STDERR, "No existe el usuario 'Sistema' (rut SISTEMA-CRON). Corre primero scripts/migrate-estado-aprobada-automatica.php\n");
    exit(1);
}
$sistemaId = (int) $sistemaId['id'];

// bandejaPendientes() (no HabitacionService::listar()) porque esta última excluye
// es_espacio_comun=1 a propósito para la pantalla "Habitaciones" — acá sí queremos
// áreas comunes, ahora que también pasan por auditoría (ver docs/areas-comunes.md).
$pendientes = (new AuditoriaService())->bandejaPendientes();

echo 'Cierre de día automático' . ($dryRun ? '  [DRY-RUN — no muta nada]' : '') . "\n";
echo str_repeat('=', 64) . "\n";

if ($pendientes === []) {
    echo "Sin habitaciones pendientes de auditoría. Nada que hacer.\n";
    exit(0);
}

echo count($pendientes) . " habitación(es) completada_pendiente_auditoria:\n";
foreach ($pendientes as $fila) {
    echo "  - {$fila['hotel_codigo']} {$fila['numero']} (id={$fila['id']})\n";
}

if ($dryRun) {
    echo "\nDRY-RUN: no se aprobó nada. Quitá --dry-run para aplicar.\n";
    exit(0);
}

$auditorias = new AuditoriaService(
    cloudbeds: new CloudbedsSyncService(CloudbedsClient::desdeConfig()),
);

$comentario = 'Auto-aprobada por cierre de día (23:55) — sin auditoría real. Ver docs de la habitación.';

$aprobadas = 0;
$fallidas  = 0;
foreach ($pendientes as $fila) {
    try {
        $auditorias->emitirVeredicto(
            (int) $fila['id'],
            $sistemaId,
            Auditoria::VEREDICTO_APROBADO_AUTOMATICO,
            $comentario,
        );
        $aprobadas++;
    } catch (AuditoriaException $e) {
        $fallidas++;
        Logger::error('auditoria', 'cierre de día automático: fallo al auto-aprobar', [
            'habitacion_id' => $fila['id'],
            'numero' => $fila['numero'],
            'codigo' => $e->codigo,
            'mensaje' => $e->getMessage(),
        ]);
    }
}

echo "\n" . str_repeat('=', 64) . "\n";
echo "Aprobadas automáticamente: {$aprobadas}\n";
echo "Fallidas: {$fallidas}\n";

exit($fallidas > 0 ? 1 : 0);
