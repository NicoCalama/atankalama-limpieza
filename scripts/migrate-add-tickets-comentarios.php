<?php

declare(strict_types=1);

/**
 * Migración: agrega la tabla tickets_comentarios (historial de comentarios de un ticket)
 * a una BD existente.
 *
 * Historial solo-append: sin edición ni borrado. `avisado` queda en 1 cuando al enviar el
 * comentario se disparó una notificación a la Supervisora (ver TicketService::comentar()).
 * En installs frescos la tabla la crea init-db.php desde los schemas; este script la agrega
 * a BDs ya creadas.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr múltiples veces.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver    = Database::driver();
$esMaria   = $driver === 'mysql' || $driver === 'mariadb';
$pdo       = Database::pdo();
$tabla     = Database::tabla('tickets_comentarios');
$tTickets  = Database::tabla('tickets');
$tUsuarios = Database::tabla('usuarios');

if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id        INT NOT NULL,
            usuario_id       INT NOT NULL,
            comentario       TEXT NOT NULL,
            avisado          TINYINT NOT NULL DEFAULT 0,
            created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    // FK con el MISMO prefijo que tickets/usuarios (en dev el prefijo es '', pero no
    // hardcodear 'tickets'/'usuarios' por si algún día hay prefijo en SQLite).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id        INTEGER NOT NULL,
            usuario_id       INTEGER NOT NULL,
            comentario       TEXT NOT NULL,
            avisado          INTEGER NOT NULL DEFAULT 0,
            created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        )"
    );
}
echo "Tabla {$tabla} lista.\n";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_comentarios_ticket ON {$tabla}(ticket_id)");
echo "Migración completa.\n";
