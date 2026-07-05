<?php

/**
 * Migración puntual: agrega el tipo de alerta 'habitacion_saltada' al CHECK
 * de la tabla alertas_activas en una BD existente.
 *
 * SQLite no permite modificar un CHECK constraint con ALTER, así que se
 * reconstruye la tabla preservando los datos. Seguro de ejecutar múltiples
 * veces: si el tipo ya está permitido, no hace nada.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$pdo = Database::pdo();

// ¿Ya está permitido el tipo? Probamos el CHECK sin dejar rastro.
$yaMigrada = false;
try {
    $pdo->beginTransaction();
    $pdo->exec(
        "INSERT INTO alertas_activas (tipo, prioridad, titulo, descripcion)
         VALUES ('habitacion_saltada', 2, '__probe__', '__probe__')"
    );
    $yaMigrada = true;
    $pdo->rollBack();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

if ($yaMigrada) {
    echo "El tipo 'habitacion_saltada' ya está permitido. Nada que hacer.\n";
    exit(0);
}

$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->beginTransaction();

$pdo->exec("
CREATE TABLE alertas_activas_nueva (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo                TEXT NOT NULL CHECK (tipo IN (
        'cloudbeds_sync_failed',
        'trabajador_en_riesgo',
        'habitacion_rechazada',
        'fin_turno_pendientes',
        'trabajador_disponible',
        'ticket_nuevo',
        'habitacion_saltada'
    )),
    prioridad           INTEGER NOT NULL CHECK (prioridad IN (0, 1, 2, 3)),
    titulo              TEXT NOT NULL,
    descripcion         TEXT NOT NULL,
    contexto_json       TEXT,
    hotel_id            INTEGER,
    created_at          TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    FOREIGN KEY (hotel_id) REFERENCES hoteles(id) ON DELETE CASCADE
);
");

$pdo->exec("
INSERT INTO alertas_activas_nueva (id, tipo, prioridad, titulo, descripcion, contexto_json, hotel_id, created_at)
SELECT id, tipo, prioridad, titulo, descripcion, contexto_json, hotel_id, created_at
  FROM alertas_activas;
");

$pdo->exec('DROP TABLE alertas_activas');
$pdo->exec('ALTER TABLE alertas_activas_nueva RENAME TO alertas_activas');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_alertas_activas_tipo ON alertas_activas(tipo)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_alertas_activas_prioridad ON alertas_activas(prioridad)');

$pdo->commit();
$pdo->exec('PRAGMA foreign_keys = ON');

// bitacora_alertas.tipo no tiene CHECK, así que no requiere migración.

echo "Tabla alertas_activas reconstruida con el tipo 'habitacion_saltada'.\n";
