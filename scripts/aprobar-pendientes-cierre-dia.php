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
 * En la pasada de la noche (desde las 20:00), ANTES de aprobar, termina toda
 * limpieza que siga sin terminar, de habitaciones y de áreas comunes (v6.19,
 * CierreDiaService::cerrarSinTerminar): la pieza pasa a por inspeccionar y esta
 * misma corrida la aprueba, pero quien no apretó «terminar» no recibe créditos.
 * Después avisa a las supervisoras de esas piezas y de las áreas rechazadas que
 * nadie volvió a pedir. La corrida de las 15:50 (cron duplicado que jefatura
 * decidió mantener) solo aprueba: a esa hora se está trabajando.
 *
 * Pensado para cron cPanel diario a las 23:55 hora de Santiago:
 *   55 23 * * * /usr/local/bin/php -q /home4/cat6852/public_html/limpieza/app_core/scripts/aprobar-pendientes-cierre-dia.php
 *
 * Uso manual:
 *   php scripts/aprobar-pendientes-cierre-dia.php --dry-run   # solo lista, no muta nada
 *   php scripts/aprobar-pendientes-cierre-dia.php
 *   php scripts/aprobar-pendientes-cierre-dia.php --noche     # hace la pasada de la noche aunque no sea de noche
 *                                                             # (--areas, el nombre de la v6.18, sigue sirviendo)
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

$opts   = getopt('', ['dry-run', 'noche', 'areas']);
$dryRun = array_key_exists('dry-run', $opts);
$noche  = array_key_exists('noche', $opts) || array_key_exists('areas', $opts) || CierreDiaService::esPasadaNocturna();

$sistemaId = Database::fetchOne("SELECT id FROM #__usuarios WHERE rut = ?", ['SISTEMA-CRON']);
if ($sistemaId === null) {
    fwrite(STDERR, "No existe el usuario 'Sistema' (rut SISTEMA-CRON). Corre primero scripts/migrate-estado-aprobada-automatica.php\n");
    exit(1);
}
$sistemaId = (int) $sistemaId['id'];

$hoy = date('Y-m-d');

echo 'Cierre de día automático' . ($dryRun ? '  [DRY-RUN — no muta nada]' : '') . "\n";
echo str_repeat('=', 64) . "\n";

if ($dryRun) {
    $consulta = new CierreDiaService();
    // bandejaPendientes() (no HabitacionService::listar()) porque esta última excluye
    // es_espacio_comun=1 a propósito para la pantalla "Habitaciones" — acá sí queremos
    // áreas comunes, ahora que también pasan por auditoría (ver docs/areas-comunes.md).
    $pendientes = $consulta->pendientes();
    echo count($pendientes) . " pieza(s) por inspeccionar (se aprueban):\n";
    foreach ($pendientes as $fila) {
        echo "  - {$fila['hotel_codigo']} {$fila['numero']} (id={$fila['id']})\n";
    }
    if ($noche) {
        $sinTerminar = $consulta->limpiezasSinTerminar();
        echo "\nLimpiezas sin terminar (se terminan sin créditos; la pieza en progreso se aprueba): " . count($sinTerminar) . "\n";
        foreach ($sinTerminar as $l) {
            $pieza = $l['estado_habitacion'] === 'en_progreso' ? 'se aprueba' : "pieza en {$l['estado_habitacion']}: no se toca";
            echo "  - {$l['hotel']} {$l['numero']} → {$l['usuario']} ({$pieza})\n";
        }
        $rechazadas = $consulta->areasRechazadas();
        echo 'Áreas comunes rechazadas sin resolver (se avisa a las supervisoras): ' . count($rechazadas) . "\n";
        foreach ($rechazadas as $a) {
            echo "  - {$a['hotel']} {$a['numero']}\n";
        }
    } else {
        echo "\nLimpiezas sin terminar: se terminan solo en la pasada de la noche (o con --noche).\n";
    }
    echo "\nDRY-RUN: no se cambió nada. Quitá --dry-run para aplicar.\n";
    exit(0);
}

$cierre = new CierreDiaService(new AuditoriaService(
    cloudbeds: new CloudbedsSyncService(CloudbedsClient::desdeConfig()),
));

// De noche, primero se terminan las limpiezas sin terminar: así quedan por inspeccionar y la
// aprobación de abajo las libera para mañana, igual que a las demás.
$cerradas = [];
$fallaNoche = false;
if ($noche) {
    try {
        $cerradas = $cierre->cerrarSinTerminar();
        echo 'Piezas con la limpieza sin terminar (se terminan sin créditos y se aprueban): ' . count($cerradas) . "\n";
        foreach ($cerradas as $p) {
            echo "  - {$p['hotel']} {$p['numero']} → " . implode(', ', $p['usuarios']) . "\n";
        }
    } catch (\Throwable $e) {
        $fallaNoche = true;
        Logger::error('checklist', 'cierre de la noche: terminar las limpiezas sin terminar falló', ['mensaje' => $e->getMessage()]);
        echo "Limpiezas sin terminar: FALLÓ — {$e->getMessage()}\n";
    }
}

$pendientes = $cierre->pendientes();
echo count($pendientes) . " pieza(s) por inspeccionar.\n";
$r = $pendientes === []
    ? ['aprobadas' => 0, 'fallidas' => 0]
    : $cierre->aprobarPendientes($sistemaId, $pendientes, array_column($cerradas, 'habitacion_id'));

echo "\n" . str_repeat('=', 64) . "\n";
echo "Aprobadas automáticamente: {$r['aprobadas']}\n";
echo "Fallidas: {$r['fallidas']}\n";

if ($noche) {
    try {
        $cierre->avisarCerradasSinTerminar($cerradas, $hoy);
        $rechazadas = $cierre->avisarAreasRechazadas($hoy);
        echo "Áreas comunes rechazadas sin resolver (aviso a supervisoras): {$rechazadas}\n";
    } catch (\Throwable $e) {
        // Las aprobaciones de arriba ya quedaron; esto solo se registra y marca la corrida como fallida.
        $fallaNoche = true;
        Logger::error('checklist', 'cierre de la noche: los avisos a las supervisoras fallaron', ['mensaje' => $e->getMessage()]);
        echo "Avisos de la noche: FALLÓ — {$e->getMessage()}\n";
    }
} else {
    echo "Limpiezas sin terminar: se terminan solo en la pasada de la noche.\n";
}

exit($r['fallidas'] > 0 || $fallaNoche ? 1 : 0);
