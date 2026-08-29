<?php

declare(strict_types=1);

/**
 * Migración: agrega tickets.idempotency_key a una BD existente.
 *
 * Fase 1 del plan "eliminar No pudimos conectar con el servidor" (ver conversación de
 * soporte): sin esto, un reintento del cliente tras una respuesta perdida por red crea un
 * ticket DUPLICADO en vez de devolver el ya creado. El cliente genera un UUID por intento
 * de envío (uno solo, se reusa en los reintentos del mismo envío) y TicketService::crear()
 * lo usa para detectar "esto ya se creó" antes de insertar de nuevo.
 *
 * UNIQUE nullable: tickets creados antes de este cambio (o sin key, ej. integraciones
 * futuras) quedan con idempotency_key = NULL — MariaDB y SQLite permiten múltiples NULL
 * en una columna UNIQUE, así que no colisionan entre sí.
 *
 * En installs frescos la columna la crea init-db.php desde los schemas; este script la
 * agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente: seguro de
 * correr varias veces.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('tickets');

// ¿Ya existe la columna? (chequeo portable)
if ($esMaria) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$tabla, 'idempotency_key']);
    $existe = (int) $stmt->fetchColumn() > 0;
} else {
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    $existe = in_array('idempotency_key', array_column($cols, 'name'), true);
}

if ($existe) {
    echo "La columna {$tabla}.idempotency_key ya existe — omito ALTER.\n";
} else {
    $tipo = $esMaria ? 'VARCHAR(64) NULL' : 'TEXT';
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN idempotency_key {$tipo}");
    echo "Columna {$tabla}.idempotency_key agregada.\n";
}

// UNIQUE: dos POST del mismo intento (reintento por red) no deben crear dos tickets.
$indice = 'idx_tickets_idempotency_key';
try {
    $pdo->exec("CREATE UNIQUE INDEX {$indice} ON {$tabla}(idempotency_key)");
    echo "Índice {$indice} creado.\n";
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    // "ya existe" es el caso idempotente esperado; cualquier otra cosa es un problema real.
    if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate key name') !== false) {
        echo "El índice {$indice} ya existe — omito CREATE.\n";
    } else {
        fwrite(STDERR, "¡ATENCIÓN! No se pudo crear el índice {$indice}: {$msg}\n");
        fwrite(STDERR, "Sin este índice, dos reintentos simultáneos podrían crear tickets duplicados.\n");
        exit(1);
    }
}

echo "Migración completa.\n";
