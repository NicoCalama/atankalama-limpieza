<?php

declare(strict_types=1);

/**
 * Política de retención automática de datos (RGPD — minimización temporal).
 *
 * Borra filas viejas en tablas de logs, sesiones, throttle de login, copilot y
 * notificaciones según los días configurados en `.env`. Reduce el tamaño de la
 * base SQLite y cumple el principio de minimización temporal.
 *
 * Uso:
 *   php scripts/cleanup-retention.php              → ejecuta y borra
 *   php scripts/cleanup-retention.php --dry-run    → solo cuenta, no borra
 *   php scripts/cleanup-retention.php --verbose    → muestra detalles
 *   php scripts/cleanup-retention.php --dry-run --verbose
 *
 * Variables de entorno relevantes (ver `.env.example`):
 *   LOG_RETENTION_DAYS_INFO       (default 90)
 *   LOG_RETENTION_DAYS_ERROR      (default 365)
 *   AUDIT_RETENTION_DAYS          (default 0 = nunca borrar)
 *   COPILOT_RETENTION_DAYS        (default 365)
 *   NOTIFICATIONS_RETENTION_DAYS  (default 90)
 *   SESSION_CLEANUP_DAYS          (default 7)
 *   THROTTLE_CLEANUP_DAYS         (default 1)
 *
 * Cron sugerido (a las 03:00 todos los días):
 *   0 3 * * * php /ruta/al/proyecto/scripts/cleanup-retention.php >> /ruta/storage/logs/retencion.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Core\Logger;

/**
 * Resultado de la limpieza de una tabla.
 */
final class ResultadoRetencion
{
    public function __construct(
        public readonly string $tabla,
        public readonly string $descripcion,
        public readonly int $filasAfectadas,
        public readonly bool $omitido = false,
        public readonly ?string $motivoOmision = null,
    ) {
    }
}

/**
 * Convierte un offset en días a un timestamp ISO 8601 UTC con milisegundos
 * (mismo formato que usa el schema en sus DEFAULTs).
 */
function fechaCorteUtc(int $dias): string
{
    $corte = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $dias . ' days');
    return $corte->format('Y-m-d\TH:i:s.v\Z');
}

/**
 * Borra (o cuenta en modo dry-run) filas con un WHERE parametrizado.
 */
function ejecutarBorrado(
    string $tabla,
    string $descripcion,
    string $whereSql,
    array $params,
    bool $dryRun,
): ResultadoRetencion {
    if ($dryRun) {
        $sqlCount = "SELECT COUNT(*) FROM {$tabla} WHERE {$whereSql}";
        $cuenta = (int) Database::fetchColumn($sqlCount, $params);
        return new ResultadoRetencion($tabla, $descripcion, $cuenta);
    }

    $sqlDelete = "DELETE FROM {$tabla} WHERE {$whereSql}";
    $borradas = Database::execute($sqlDelete, $params);
    return new ResultadoRetencion($tabla, $descripcion, $borradas);
}

/**
 * 1) Sesiones expiradas hace más de N días (huérfanas que el sliding window no limpió).
 */
function limpiarSesiones(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'sesiones',
        "expiradas hace > {$dias} día(s)",
        'expires_at < ?',
        [$corte],
        $dryRun,
    );
}

/**
 * 2) Intentos de login fuera de la ventana de throttle (default 1 día).
 */
function limpiarIntentosLogin(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'intentos_login',
        "más antiguos que {$dias} día(s)",
        'creado_at < ?',
        [$corte],
        $dryRun,
    );
}

/**
 * 3a) Logs de evento INFO/WARNING más antiguos que LOG_RETENTION_DAYS_INFO.
 */
function limpiarLogsInfoWarning(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'logs_eventos',
        "INFO/WARNING > {$dias} día(s)",
        "nivel IN ('INFO', 'WARNING') AND created_at < ?",
        [$corte],
        $dryRun,
    );
}

/**
 * 3b) Logs de evento ERROR más antiguos que LOG_RETENTION_DAYS_ERROR (más laxo).
 */
function limpiarLogsError(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'logs_eventos',
        "ERROR > {$dias} día(s)",
        "nivel = 'ERROR' AND created_at < ?",
        [$corte],
        $dryRun,
    );
}

/**
 * 4) Audit log: solo si AUDIT_RETENTION_DAYS > 0 (default 0 = nunca borrar).
 */
function limpiarAuditLog(int $dias, bool $dryRun): ResultadoRetencion
{
    if ($dias <= 0) {
        return new ResultadoRetencion(
            'audit_log',
            'desactivado (AUDIT_RETENTION_DAYS=0, compliance)',
            0,
            true,
            'AUDIT_RETENTION_DAYS=0 — preservación indefinida por compliance',
        );
    }
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'audit_log',
        "> {$dias} día(s)",
        'created_at < ?',
        [$corte],
        $dryRun,
    );
}

/**
 * 5a) Copilot: borra primero los mensajes huérfanos según fecha; el ON DELETE CASCADE
 * de copilot_mensajes se encarga al borrar la conversación.
 *
 * Política: una conversación es "vieja" si su updated_at < corte. Se borra completa
 * con sus mensajes (cascade). Si quedan mensajes huérfanos por algún motivo,
 * también se limpian.
 */
function limpiarCopilotConversaciones(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'copilot_conversaciones',
        "updated_at < {$dias} día(s) (mensajes caen por cascade)",
        'updated_at < ?',
        [$corte],
        $dryRun,
    );
}

/**
 * 5b) Mensajes huérfanos del copilot: por si quedó alguno con conversacion_id ya borrado
 * (el cascade debería haberlos eliminado, pero limpiamos por seguridad).
 */
function limpiarCopilotMensajesHuerfanos(bool $dryRun): ResultadoRetencion
{
    return ejecutarBorrado(
        'copilot_mensajes',
        'huérfanos (sin conversación)',
        'conversacion_id NOT IN (SELECT id FROM copilot_conversaciones)',
        [],
        $dryRun,
    );
}

/**
 * 6) Notificaciones leídas más antiguas que NOTIFICATIONS_RETENTION_DAYS.
 * Se conservan las no leídas siempre (el usuario aún no las vio).
 */
function limpiarNotificacionesLeidas(int $dias, bool $dryRun): ResultadoRetencion
{
    $corte = fechaCorteUtc($dias);
    return ejecutarBorrado(
        'notificaciones',
        "leídas > {$dias} día(s) (no leídas se conservan)",
        'leida = 1 AND created_at < ?',
        [$corte],
        $dryRun,
    );
}

/**
 * Imprime una línea de resultado en STDOUT.
 */
function imprimirResultado(ResultadoRetencion $r, bool $dryRun, bool $verbose): void
{
    $verbo = $dryRun ? 'borraría' : 'borradas';
    $tag = $dryRun ? '[DRY-RUN]' : '[OK]';

    if ($r->omitido) {
        echo sprintf(
            "  %s %-30s 0 filas — %s\n",
            $tag,
            $r->tabla,
            $r->motivoOmision ?? 'omitido',
        );
        return;
    }

    echo sprintf(
        "  %s %-30s %s %d fila(s) — %s\n",
        $tag,
        $r->tabla,
        $verbo,
        $r->filasAfectadas,
        $r->descripcion,
    );

    if ($verbose && $r->filasAfectadas === 0) {
        echo "       (sin filas que cumplan el criterio)\n";
    }
}

/**
 * Punto de entrada.
 */
function ejecutarRetencion(bool $dryRun, bool $verbose): int
{
    $inicio = microtime(true);

    $diasLogsInfo     = Config::getInt('LOG_RETENTION_DAYS_INFO', 90);
    $diasLogsError    = Config::getInt('LOG_RETENTION_DAYS_ERROR', 365);
    $diasAudit        = Config::getInt('AUDIT_RETENTION_DAYS', 0);
    $diasCopilot      = Config::getInt('COPILOT_RETENTION_DAYS', 365);
    $diasNotif        = Config::getInt('NOTIFICATIONS_RETENTION_DAYS', 90);
    $diasSesiones     = Config::getInt('SESSION_CLEANUP_DAYS', 7);
    $diasThrottle     = Config::getInt('THROTTLE_CLEANUP_DAYS', 1);

    echo "\n=== Retención automática de datos (Atankalama Limpieza) ===\n";
    echo $dryRun
        ? "Modo: DRY-RUN (no se borrará nada, solo se cuenta)\n\n"
        : "Modo: EJECUCIÓN REAL (se borrarán filas)\n\n";

    if ($verbose) {
        echo "Configuración cargada desde .env:\n";
        echo "  LOG_RETENTION_DAYS_INFO       = {$diasLogsInfo}\n";
        echo "  LOG_RETENTION_DAYS_ERROR      = {$diasLogsError}\n";
        echo "  AUDIT_RETENTION_DAYS          = {$diasAudit}" . ($diasAudit === 0 ? " (desactivado)" : "") . "\n";
        echo "  COPILOT_RETENTION_DAYS        = {$diasCopilot}\n";
        echo "  NOTIFICATIONS_RETENTION_DAYS  = {$diasNotif}\n";
        echo "  SESSION_CLEANUP_DAYS          = {$diasSesiones}\n";
        echo "  THROTTLE_CLEANUP_DAYS         = {$diasThrottle}\n\n";
    }

    /** @var list<ResultadoRetencion> $resultados */
    $resultados = [];

    try {
        // En dry-run no usamos transacción (solo SELECTs); en ejecución real, sí.
        if ($dryRun) {
            $resultados[] = limpiarSesiones($diasSesiones, true);
            $resultados[] = limpiarIntentosLogin($diasThrottle, true);
            $resultados[] = limpiarLogsInfoWarning($diasLogsInfo, true);
            $resultados[] = limpiarLogsError($diasLogsError, true);
            $resultados[] = limpiarAuditLog($diasAudit, true);
            $resultados[] = limpiarCopilotConversaciones($diasCopilot, true);
            $resultados[] = limpiarCopilotMensajesHuerfanos(true);
            $resultados[] = limpiarNotificacionesLeidas($diasNotif, true);
        } else {
            Database::transaction(function () use (
                &$resultados,
                $diasSesiones,
                $diasThrottle,
                $diasLogsInfo,
                $diasLogsError,
                $diasAudit,
                $diasCopilot,
                $diasNotif,
            ): void {
                $resultados[] = limpiarSesiones($diasSesiones, false);
                $resultados[] = limpiarIntentosLogin($diasThrottle, false);
                $resultados[] = limpiarLogsInfoWarning($diasLogsInfo, false);
                $resultados[] = limpiarLogsError($diasLogsError, false);
                $resultados[] = limpiarAuditLog($diasAudit, false);
                $resultados[] = limpiarCopilotConversaciones($diasCopilot, false);
                $resultados[] = limpiarCopilotMensajesHuerfanos(false);
                $resultados[] = limpiarNotificacionesLeidas($diasNotif, false);
            });
        }
    } catch (\Throwable $e) {
        // Logger::error si falla algo (queda en logs_eventos, salvo que la BD esté
        // caída — en cuyo caso cae al fallback file).
        Logger::error('retencion', 'Falló cleanup-retention: ' . $e->getMessage(), [
            'dry_run' => $dryRun,
            'exception_class' => $e::class,
        ]);
        fwrite(STDERR, "\n[ERROR] No pudimos completar la limpieza: " . $e->getMessage() . "\n");
        return 1;
    }

    echo "Resumen por tabla:\n";
    $totalBorradas = 0;
    foreach ($resultados as $r) {
        imprimirResultado($r, $dryRun, $verbose);
        if (!$r->omitido) {
            $totalBorradas += $r->filasAfectadas;
        }
    }

    $duracionMs = (int) round((microtime(true) - $inicio) * 1000);
    $verbo = $dryRun ? 'serían borradas' : 'borradas';
    echo "\nTotal: {$totalBorradas} fila(s) {$verbo} en {$duracionMs} ms.\n";

    // Resumen al final del run en logs_eventos (solo en ejecución real;
    // en dry-run no queremos contaminar logs).
    if (!$dryRun) {
        $resumenContexto = [
            'total_borradas' => $totalBorradas,
            'duracion_ms' => $duracionMs,
            'por_tabla' => array_map(
                static fn (ResultadoRetencion $r): array => [
                    'tabla' => $r->tabla,
                    'descripcion' => $r->descripcion,
                    'filas' => $r->filasAfectadas,
                    'omitido' => $r->omitido,
                ],
                $resultados,
            ),
        ];
        Logger::info(
            'retencion',
            "Retención automática completada: {$totalBorradas} fila(s) borradas",
            $resumenContexto,
        );
    }

    return 0;
}

// --- CLI entry point ---
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    Config::load(dirname(__DIR__));

    $dryRun = in_array('--dry-run', $argv, true);
    $verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

    exit(ejecutarRetencion($dryRun, $verbose));
}
