<?php

/**
 * Migración puntual: agrega la tabla notificaciones a una BD existente.
 * Seguro de ejecutar múltiples veces (usa CREATE TABLE IF NOT EXISTS).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$pdo = Database::pdo();

$pdo->exec("
CREATE TABLE IF NOT EXISTS notificaciones (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id  INTEGER NOT NULL,
    tipo        TEXT    NOT NULL DEFAULT 'general',
    titulo      TEXT    NOT NULL,
    cuerpo      TEXT    NOT NULL,
    url         TEXT    NOT NULL DEFAULT '/home',
    leida       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notificaciones_usuario ON notificaciones(usuario_id, leida);
CREATE INDEX IF NOT EXISTS idx_notificaciones_created ON notificaciones(created_at);
");

echo "Tabla notificaciones creada (o ya existía).\n";
