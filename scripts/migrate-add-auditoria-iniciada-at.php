<?php

declare(strict_types=1);

/**
 * Migración: agrega el instante de apertura de la inspección a cada ejecución.
 *
 *   ejecuciones_checklist: auditoria_iniciada_at (nullable)
 *
 * Es la base del KPI "tiempo por auditación" de la Supervisora (docs/kpis-sueldos.md,
 * S1.2): la supervisora abre la pieza en /auditoria/{id} → se graba el instante (se
 * sobreescribe en cada apertura, así la duración es la de la sesión real de revisión);
 * el fin es auditorias.created_at (instante del veredicto). Solo habrá histórico desde
 * que se despliegue: las inspecciones previas quedan sin dato (y el KPI las ignora).
 *
 * En installs frescos esta columna la crea init-db.php desde los schemas; este script la
 * agrega a BDs ya creadas. Portable (SQLite dev + MariaDB prod) e idempotente.
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

$columnaExiste = static function (string $tabla, string $columna) use ($pdo, $esMaria): bool {
    if ($esMaria) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$tabla, $columna]);
        return (int) $stmt->fetchColumn() > 0;
    }
    $cols = $pdo->query('PRAGMA table_info(' . $tabla . ')')->fetchAll(\PDO::FETCH_ASSOC);
    return in_array($columna, array_column($cols, 'name'), true);
};

$agregar = static function (string $tablaLogica, string $columna, string $tipoSqlite, string $tipoMaria) use ($pdo, $esMaria, $columnaExiste): void {
    $tabla = Database::tabla($tablaLogica);
    if ($columnaExiste($tabla, $columna)) {
        echo "  {$tabla}.{$columna} ya existe — omito.\n";
        return;
    }
    $tipo = $esMaria ? $tipoMaria : $tipoSqlite;
    $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$columna} {$tipo}");
    echo "  {$tabla}.{$columna} agregada.\n";
};

echo "Tiempo por auditación (apertura de la inspección):\n";
$agregar('ejecuciones_checklist', 'auditoria_iniciada_at', 'TEXT', 'VARCHAR(30) NULL');

echo "Migración completa.\n";
