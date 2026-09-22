<?php

declare(strict_types=1);

/**
 * Migración: agrega la tabla tickets_asignados (asignación múltiple y por grupos a tickets)
 * a una BD existente y migra los asignados previos (asignado_a).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver    = Database::driver();
$esMaria   = $driver === 'mysql' || $driver === 'mariadb';
$pdo       = Database::pdo();
$tabla     = Database::tabla('tickets_asignados');
$tTickets  = Database::tabla('tickets');
$tUsuarios = Database::tabla('usuarios');

if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            ticket_id        INT NOT NULL,
            usuario_id       INT NOT NULL,
            asignado_por     INT NOT NULL,
            created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            PRIMARY KEY (ticket_id, usuario_id),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE,
            FOREIGN KEY (asignado_por) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            ticket_id        INTEGER NOT NULL,
            usuario_id       INTEGER NOT NULL,
            asignado_por     INTEGER NOT NULL,
            created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            PRIMARY KEY (ticket_id, usuario_id),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE,
            FOREIGN KEY (asignado_por) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        )"
    );
}
echo "Tabla {$tabla} lista.\n";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_asignados_usuario ON {$tabla}(usuario_id)");

// Migrar asignaciones existentes de forma idempotente
$insertSql = $esMaria
    ? "INSERT IGNORE INTO {$tabla} (ticket_id, usuario_id, asignado_por, created_at)
       SELECT id, asignado_a, levantado_por, COALESCE(asignado_at, created_at)
         FROM {$tTickets}
        WHERE asignado_a IS NOT NULL"
    : "INSERT OR IGNORE INTO {$tabla} (ticket_id, usuario_id, asignado_por, created_at)
       SELECT id, asignado_a, levantado_por, COALESCE(asignado_at, created_at)
         FROM {$tTickets}
        WHERE asignado_a IS NOT NULL";

$pdo->exec($insertSql);
echo "Asignaciones previas migradas a {$tabla}.\n";
echo "Migración completa.\n";
