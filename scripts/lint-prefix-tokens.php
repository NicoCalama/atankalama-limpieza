<?php
/**
 * lint-prefix-tokens.php — Verifica que TODA referencia a tabla en el SQL de la app
 * use el token de prefijo `#__`.
 *
 * Por qué existe: en SQLite el prefijo (DB_PREFIX) es '' y el token #__ se borra, así
 * que una query tokenizada y una sin tokenizar se comportan IGUAL → la suite PHPUnit
 * (sobre SQLite) NO detecta un token faltante. Este linter sí lo detecta, sin necesidad
 * de un MariaDB. Es la red de seguridad de la tokenización (Fase 1 de la migración).
 *
 * Uso:  php scripts/lint-prefix-tokens.php
 * Sale 0 si todo está tokenizado; 1 (y lista las faltas) si hay referencias sin #__.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

// Las 32 tablas (deben coincidir con docs/database-schema.sql).
$tables = [
    'permisos', 'roles', 'rol_permisos', 'usuarios', 'usuarios_roles', 'sesiones',
    'contrasenas_temporales', 'intentos_login', 'hoteles', 'tipos_habitacion', 'habitaciones',
    'turnos', 'usuarios_turnos', 'asignaciones', 'checklists_template', 'items_checklist',
    'ejecuciones_checklist', 'ejecuciones_items', 'auditorias', 'alertas_activas', 'bitacora_alertas',
    'alertas_config', 'cloudbeds_sync_historial', 'cloudbeds_config', 'tickets', 'logs_eventos',
    'audit_log', 'copilot_conversaciones', 'copilot_mensajes', 'notificaciones_disponibilidad',
    'push_subscriptions', 'notificaciones',
];
// Match del nombre más largo primero (ej. usuarios_roles antes que usuarios).
usort($tables, static fn($a, $b) => strlen($b) <=> strlen($a));
$alt = implode('|', array_map('preg_quote', $tables));

// Referencia a tabla = palabra clave SQL de posición de tabla + nombre de tabla SIN #__ delante.
$pattern = '/\b(FROM|JOIN|INTO|UPDATE)\s+(?!#__)(' . $alt . ')\b/i';

$dirs = ['src', 'scripts', 'database/seeds', 'tests/Support'];
$findings = [];

foreach ($dirs as $d) {
    $base = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $d);
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        // Scripts solo-SQLite (PDO crudo, dialecto SQLite, sin prefijo): no corren contra
        // MariaDB, así que el token #__ no aplica. Tienen un guard de driver al inicio.
        $soloSqlite = ['lint-prefix-tokens.php', 'migrate-add-notificaciones.php'];
        if (in_array(basename($path), $soloSqlite, true)) {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (preg_match_all($pattern, $line, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $findings[] = sprintf('%s:%d  -> %s %s', $rel, $i + 1, strtoupper($hit[1]), $hit[2]);
                }
            }
        }
    }
}

if (!$findings) {
    echo "OK: todas las referencias de tabla usan el prefijo #__\n";
    exit(0);
}

echo 'Referencias de tabla SIN prefijo #__ (' . count($findings) . "):\n";
echo implode("\n", $findings) . "\n";
exit(1);
