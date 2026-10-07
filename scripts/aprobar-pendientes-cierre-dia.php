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
 * En la pasada de la noche (desde las 20:00) cierra además el día de las áreas
 * comunes (v6.18, CierreDiaService::cerrarAreasComunes): las que quedan en
 * progreso siguen mañana en la cola de la misma persona y se avisa a las
 * supervisoras, igual que de las rechazadas que nadie volvió a pedir. La corrida
 * de las 15:50 (cron duplicado que jefatura decidió mantener) no toca las áreas.
 *
 * Pensado para cron cPanel diario a las 23:55 hora de Santiago:
 *   55 23 * * * /usr/local/bin/php -q /home4/cat6852/public_html/limpieza/app_core/scripts/aprobar-pendientes-cierre-dia.php
 *
 * Uso manual:
 *   php scripts/aprobar-pendientes-cierre-dia.php --dry-run   # solo lista, no muta nada
 *   php scripts/aprobar-pendientes-cierre-dia.php
 *   php scripts/aprobar-pendientes-cierre-dia.php --areas     # cierra las áreas aunque no sea de noche
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
use Atankalama\Limpieza\Services\AuditoriaService;
use Atankalama\Limpieza\Services\CierreDiaService;
use Atankalama\Limpieza\Services\CloudbedsClient;
use Atankalama\Limpieza\Services\CloudbedsSyncService;

Config::load(dirname(__DIR__));

$opts        = getopt('', ['dry-run', 'areas']);
$dryRun      = array_key_exists('dry-run', $opts);
$cerrarAreas =array_key_exists('areas', $opts) || CierreDiaService::esPasadaNocturna();

$sistemaId = Database::fetchOne("SELECT id FROM #__usuarios WHERE rut = ?", ['SISTEMA-CRON']);
if ($sistemaId === null) {
    fwrite(STDERR, "No existe el usuario 'Sistema' (rut SISTEMA-CRON). Corre primero scripts/migrate-estado-aprobada-automatica.php\n");
    exit(1);
}
$sistemaId = (int) $sistemaId['id'];

$hoy    = date('Y-m-d');
$manana = date('Y-m-d', strtotime('+1 day'));

// bandejaPendientes() (no HabitacionService::listar()) porque esta última excluye
// es_espacio_comun=1 a propósito para la pantalla "Habitaciones" — acá sí queremos
// áreas comunes, ahora que también pasan por auditoría (ver docs/areas-comunes.md).
$pendientes = (new CierreDiaService())->pendientes();

echo 'Cierre de día automático' . ($dryRun ? '  [DRY-RUN — no muta nada]' : '') . "\n";
echo str_repeat('=', 64) . "\n";

if ($pendientes === []) {
    echo "Sin habitaciones pendientes de auditoría.\n";
} else {
    echo count($pendientes) . " habitación(es) completada_pendiente_auditoria:\n";
    foreach ($pendientes as $fila) {
        echo "  - {$fila['hotel_codigo']} {$fila['numero']} (id={$fila['id']})\n";
    }
}

if ($dryRun) {
    if ($cerrarAreas) {
        $consulta = new CierreDiaService();
        $enProgreso = $consulta->areasEnProgreso($hoy);
        $rechazadas = $consulta->areasRechazadas();
        echo "\nÁreas comunes en progreso (pasarían a la cola del {$manana} de la misma persona): " . count($enProgreso) . "\n";
        foreach ($enProgreso as $a) {
            echo "  - {$a['hotel']} {$a['numero']} → {$a['usuario']}\n";
        }
        echo 'Áreas comunes rechazadas sin resolver (se avisa a las supervisoras): ' . count($rechazadas) . "\n";
        foreach ($rechazadas as $a) {
            echo "  - {$a['hotel']} {$a['numero']}\n";
        }
    } else {
        echo "\nÁreas comunes: se cierran solo en la pasada de la noche (o con --areas).\n";
    }
    echo "\nDRY-RUN: no se cambió nada. Quitá --dry-run para aplicar.\n";
    exit(0);
}

$cierre = new CierreDiaService(new AuditoriaService(
    cloudbeds: new CloudbedsSyncService(CloudbedsClient::desdeConfig()),
));
// Primero las pendientes: un área por inspeccionar queda aprobada y, desde mañana, sin asignar.
$r = $pendientes === [] ? ['aprobadas' => 0, 'fallidas' => 0] : $cierre->aprobarPendientes($sistemaId, $pendientes);

echo "\n" . str_repeat('=', 64) . "\n";
echo "Aprobadas automáticamente: {$r['aprobadas']}\n";
echo "Fallidas: {$r['fallidas']}\n";

$fallaAreas = false;
if ($cerrarAreas) {
    try {
        $a = $cierre->cerrarAreasComunes($hoy, $manana);
        echo "Áreas comunes en progreso que siguen mañana en la misma cola: {$a['arrastradas']}\n";
        echo "Áreas comunes rechazadas sin resolver (aviso a supervisoras): {$a['rechazadas']}\n";
    } catch (\Throwable $e) {
        // Las aprobaciones de arriba ya quedaron; esto solo se registra y marca la corrida como fallida.
        $fallaAreas = true;
        Logger::error('espacios', 'cierre del día de las áreas comunes falló', ['mensaje' => $e->getMessage()]);
        echo "Cierre de áreas comunes: FALLÓ — {$e->getMessage()}\n";
    }
} else {
    echo "Áreas comunes: se cierran solo en la pasada de la noche.\n";
}

exit($r['fallidas'] > 0 || $fallaAreas ? 1 : 0);
