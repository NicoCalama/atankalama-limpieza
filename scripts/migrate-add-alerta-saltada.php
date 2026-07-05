<?php

declare(strict_types=1);

/**
 * Migración: agrega el tipo de alerta 'habitacion_saltada' al CHECK de la tabla
 * alertas_activas en una BD existente. Necesario para la válvula de escape
 * "No puedo terminar ahora" del flujo "una habitación a la vez"
 * (docs/home-trabajador.md §7).
 *
 * En installs frescos el tipo lo traen los schemas; este script lo agrega a BDs
 * ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: si el tipo ya
 * está permitido, no hace nada.
 *
 * SQLite no permite modificar un CHECK con ALTER → se reconstruye la tabla
 * preservando los datos. MariaDB sí permite ALTER ... DROP/ADD CONSTRAINT.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('alertas_activas');

// Lista completa de tipos válidos tras la migración (debe coincidir con los schemas).
$tipos = [
    'cloudbeds_sync_failed',
    'trabajador_en_riesgo',
    'habitacion_rechazada',
    'fin_turno_pendientes',
    'trabajador_disponible',
    'ticket_nuevo',
    'habitacion_saltada',
];
$listaSql = "'" . implode("', '", $tipos) . "'";

// ¿Ya está permitido el tipo? Leemos la definición real de la tabla (más robusto
// que un INSERT de prueba: no confunde un error de conexión con "falta migrar").
if ($esMaria) {
    $row = $pdo->query("SHOW CREATE TABLE `{$tabla}`")->fetch(\PDO::FETCH_NUM);
    $definicion = (string) ($row[1] ?? '');
} else {
    // fetchAll sobre un statement temporal (sin variable persistente): evita dejar un
    // cursor abierto sobre sqlite_master, que bloquearía el DROP TABLE de la reconstrucción.
    $filas = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($tabla)
    )->fetchAll(\PDO::FETCH_COLUMN);
    $definicion = (string) ($filas[0] ?? '');
}

if ($definicion === '') {
    fwrite(STDERR, "No existe la tabla {$tabla}. ¿La BD está inicializada? Corré scripts/init-db.php primero.\n");
    exit(1);
}

if (str_contains($definicion, 'habitacion_saltada')) {
    echo "El tipo 'habitacion_saltada' ya está permitido en {$tabla}. Nada que hacer.\n";
    exit(0);
}

if ($esMaria) {
    // Ubicar el nombre autogenerado del CHECK de la columna `tipo` (el que enumera
    // los tipos; se distingue del CHECK de `prioridad` por su cláusula).
    $stmt = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND CHECK_CLAUSE LIKE '%cloudbeds_sync_failed%'"
    );
    $constraint = (string) $stmt->fetchColumn();
    if ($constraint === '') {
        fwrite(STDERR, "No se encontró el CHECK de tipos en {$tabla}. Aplicá el ALTER manual del runbook (docs/deploy-cpanel.md).\n");
        exit(1);
    }
    $pdo->exec("ALTER TABLE `{$tabla}` DROP CONSTRAINT `{$constraint}`");
    $pdo->exec("ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$constraint}` CHECK (tipo IN ({$listaSql}))");
    echo "CHECK de {$tabla} actualizado con el tipo 'habitacion_saltada'.\n";
    exit(0);
}

// --- SQLite: reconstrucción de la tabla (no permite modificar un CHECK con ALTER) ---
$hoteles = Database::tabla('hoteles');
$nueva   = $tabla . '_nueva';

$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->beginTransaction();

$pdo->exec("
CREATE TABLE {$nueva} (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL CHECK (tipo IN ({$listaSql})),
    prioridad           INTEGER NOT NULL CHECK (prioridad IN (0, 1, 2, 3)),
    titulo              TEXT NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,
    hotel_id            INTEGER,
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (hotel_id) REFERENCES {$hoteles}(id) ON DELETE CASCADE
);
");

$pdo->exec("
INSERT INTO {$nueva} (id, tipo, prioridad, titulo, descripcion, contexto_json, hotel_id, created_at)
SELECT id, tipo, prioridad, titulo, descripcion, contexto_json, hotel_id, created_at
  FROM {$tabla};
");

$pdo->exec("DROP TABLE {$tabla}");
$pdo->exec("ALTER TABLE {$nueva} RENAME TO {$tabla}");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_alertas_activas_tipo ON {$tabla}(tipo)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_alertas_activas_prioridad ON {$tabla}(prioridad)");

$pdo->commit();
$pdo->exec('PRAGMA foreign_keys = ON');

// bitacora_alertas.tipo no tiene CHECK, así que no requiere migración.

echo "Tabla {$tabla} reconstruida con el tipo 'habitacion_saltada'.\n";
