<?php

declare(strict_types=1);

/**
 * Migración: agrega el tipo de alerta 'inventario_cambios_pendientes' al CHECK de
 * la tabla alertas_activas en una BD existente. Necesario para la detección de
 * altas/bajas de habitaciones en Cloudbeds (docs/cloudbeds-import-inventario.md).
 *
 * En installs frescos el tipo lo traen los schemas; este script lo agrega a BDs
 * ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: si el tipo ya
 * está permitido, no hace nada.
 *
 * SQLite no permite modificar un CHECK con ALTER → se reconstruye la tabla
 * preservando los datos. MariaDB sí permite ALTER ... DROP/ADD CONSTRAINT.
 *
 * OJO — el PERMISO nuevo ('habitaciones.importar_inventario') NO lo aplica este
 * script: lo propaga el sync RBAC idempotente de scripts/init-db.php (INSERT OR
 * IGNORE del catálogo + rol_permisos). En prod: correr init-db.php o insertar el
 * permiso a mano (ver docs/deploy-cpanel.md).
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('alertas_activas');

// Lista completa de tipos válidos tras la migración (debe coincidir con los schemas
// y con AlertaActiva::TIPOS_VALIDOS).
$tipos = [
    'cloudbeds_sync_failed',
    'trabajador_en_riesgo',
    'habitacion_rechazada',
    'fin_turno_pendientes',
    'trabajador_disponible',
    'ticket_nuevo',
    'habitacion_saltada',
    'inventario_cambios_pendientes',
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

if (str_contains($definicion, 'inventario_cambios_pendientes')) {
    echo "El tipo 'inventario_cambios_pendientes' ya está permitido en {$tabla}. Nada que hacer.\n";
    exit(0);
}

if ($esMaria) {
    // Ubicar el nombre autogenerado del CHECK de la columna `tipo` (el que enumera
    // los tipos; se distingue del CHECK de `prioridad` por su cláusula).
    // Se acota por TABLE_NAME además del schema: en la BD compartida de cPanel conviven
    // varias apps en la misma DATABASE(), y no queremos depender de que la subcadena
    // 'cloudbeds_sync_failed' sea única en todo el esquema. $tabla es un valor interno
    // (Database::tabla), no input de usuario, así que la interpolación es segura.
    $stmt = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$tabla}'
            AND CHECK_CLAUSE LIKE '%cloudbeds_sync_failed%'"
    );
    $constraint = (string) $stmt->fetchColumn();
    if ($constraint === '') {
        fwrite(STDERR, "No se encontró el CHECK de tipos en {$tabla}. Aplicá el ALTER manual del runbook (docs/deploy-cpanel.md).\n");
        exit(1);
    }
    // DROP + ADD en un ÚNICO ALTER: MariaDB lo aplica atómicamente (un solo paso de
    // metadata). Como dos statements separados, cada ALTER hace commit implícito y una
    // interrupción entre ambos dejaría la tabla sin el CHECK de 'tipo' hasta arreglo manual.
    $pdo->exec(
        "ALTER TABLE `{$tabla}`
            DROP CONSTRAINT `{$constraint}`,
            ADD CONSTRAINT `{$constraint}` CHECK (tipo IN ({$listaSql}))"
    );
    echo "CHECK de {$tabla} actualizado con el tipo 'inventario_cambios_pendientes'.\n";
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

echo "Tabla {$tabla} reconstruida con el tipo 'inventario_cambios_pendientes'.\n";
