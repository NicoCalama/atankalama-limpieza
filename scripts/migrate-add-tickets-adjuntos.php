<?php

declare(strict_types=1);

/**
 * Migración: agrega la tabla tickets_adjuntos (fotos de tickets) a una BD existente.
 *
 * Soporta adjuntar fotos al crear un ticket y/o al cerrarlo (docs/tickets — adjuntos).
 * Los archivos físicos viven en public/uploads/tickets/{AAAA}/{MM}/; esta tabla solo
 * guarda la ruta relativa + metadata. En installs frescos la tabla la crea init-db.php
 * desde los schemas; este script la agrega a BDs ya creadas.
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
$tabla     = Database::tabla('tickets_adjuntos');
$tTickets  = Database::tabla('tickets');
$tUsuarios = Database::tabla('usuarios');

if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id        INT NOT NULL,
            ruta             VARCHAR(500) NOT NULL,
            nombre_original  VARCHAR(255),
            tamano_bytes     INT NOT NULL,
            contexto         VARCHAR(20) NOT NULL DEFAULT 'creacion' CHECK (contexto IN ('creacion', 'cierre')),
            subido_por       INT NOT NULL,
            created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (subido_por) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    // FK con el MISMO prefijo que tickets/usuarios (en dev el prefijo es '', pero no
    // hardcodear 'tickets'/'usuarios' por si algún día hay prefijo en SQLite).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tabla} (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id        INTEGER NOT NULL,
            ruta             TEXT NOT NULL,
            nombre_original  TEXT,
            tamano_bytes     INTEGER NOT NULL,
            contexto         TEXT NOT NULL DEFAULT 'creacion' CHECK (contexto IN ('creacion', 'cierre')),
            subido_por       INTEGER NOT NULL,
            created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            FOREIGN KEY (ticket_id) REFERENCES {$tTickets}(id) ON DELETE CASCADE,
            FOREIGN KEY (subido_por) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT
        )"
    );
}
echo "Tabla {$tabla} lista.\n";

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_adjuntos_ticket ON {$tabla}(ticket_id)");
echo "Migración completa.\n";
