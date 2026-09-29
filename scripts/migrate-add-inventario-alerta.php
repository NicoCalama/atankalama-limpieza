<?php

declare(strict_types=1);

/**
 * Migración: amplía el CHECK de `alertas_activas.tipo` a la lista completa de
 * AlertaActiva::TIPOS_VALIDOS en una BD existente.
 *
 * Nació en la v2.4 para 'inventario_cambios_pendientes' (docs/cloudbeds-import-inventario.md)
 * y desde la v6.15 cubre cualquier tipo que falte, incluido 'aprobacion_deshecha' (v6.10). En
 * installs frescos los tipos los traen los schemas; este script los agrega a BDs ya creadas.
 * Portable (SQLite dev + MariaDB prod) e idempotente: si ya están todos, no hace nada.
 *
 * - SQLite no permite modificar un CHECK con ALTER → se reconstruye la tabla preservando
 *   los datos.
 * - MariaDB: `MODIFY COLUMN … CHECK (…)`, que reemplaza la definición entera de la columna.
 *   Hasta la v6.15 este script hacía `DROP CONSTRAINT tipo, ADD CONSTRAINT …`, y eso da
 *   ERROR 1091: MariaDB no deja borrar por nombre un CHECK declarado en la columna, que es
 *   como lo trae la base de producción desde el dump del 07/07/2026. Probado en 10.6 y 10.11.
 *   Ver docs/incidente-2026-09-23.md §5.
 *
 * OJO — el PERMISO de la v2.4 ('habitaciones.importar_inventario') NO lo aplica este
 * script: lo propaga el sync RBAC idempotente de scripts/init-db.php (INSERT OR
 * IGNORE del catálogo + rol_permisos). En prod: correr init-db.php o insertar el
 * permiso a mano (ver docs/deploy-cpanel.md).
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\AlertaActiva;
use Atankalama\Limpieza\Services\EsquemaService;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();
$tabla   = Database::tabla('alertas_activas');

// La lista sale del código, no de una copia a mano: así este script no se vuelve a quedar
// atrás cuando se suma un tipo (le pasó con 'aprobacion_deshecha').
$tipos    = AlertaActiva::TIPOS_VALIDOS;
$listaSql = "'" . implode("', '", $tipos) . "'";

// ¿Qué tipos faltan? Leemos la definición real de la tabla (más robusto que un INSERT de
// prueba: no confunde un error de conexión con "falta migrar").
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

/**
 * Lo que la base RECHAZA de verdad, según el verificador de esquema: lee todos los CHECK vivos
 * de la columna (de columna y de tabla) y se queda con lo que aceptan todos a la vez. Buscar
 * los tipos en el texto de la definición no alcanza: si hubiera dos CHECK sobre `tipo`, basta
 * con que uno los nombre para que el texto «los tenga» mientras el otro sigue rechazando.
 */
$rechazoVivo = static function (): ?string {
    EsquemaService::limpiarCache();
    foreach ((new EsquemaService())->faltantes(true)['checks'] as $check) {
        if (str_starts_with($check, 'alertas_activas.tipo:')) {
            return $check;
        }
    }
    return null;
};

// Tipos que el texto de la definición no nombra: lo que hay que agregar al CHECK de la columna.
$faltan  = array_values(array_filter($tipos, static fn(string $t): bool => !str_contains($definicion, "'{$t}'")));
$rechazo = $rechazoVivo();

if ($faltan === [] && $rechazo === null) {
    echo "Todos los tipos de alerta ya están permitidos en {$tabla}. Nada que hacer.\n";
    exit(0);
}

if ($esMaria) {
    echo 'La base rechaza tipos de alerta en ' . $tabla . ': ' . ($rechazo ?? implode(', ', $faltan)) . "\n";
    // El MODIFY reemplaza el CHECK de la columna y se puede repetir sin efectos: se hace
    // siempre que la base rechace, y después se vuelve a medir. Si sigue rechazando, el que
    // rechaza es OTRO CHECK (de tabla), que el MODIFY no toca.
    // $tabla es un valor interno (Database::tabla), no input de usuario: interpolar es seguro.
    $pdo->exec("ALTER TABLE `{$tabla}` MODIFY COLUMN tipo VARCHAR(40) NOT NULL CHECK (tipo IN ({$listaSql}))");

    $rechazo = $rechazoVivo();
    if ($rechazo !== null) {
        fwrite(STDERR, "El CHECK de la columna se amplió, pero la base sigue rechazando: {$rechazo}\n"
            . "Hay otro CHECK sobre `tipo`. Revisá SHOW CREATE TABLE {$tabla} y borrá ese.\n");
        exit(1);
    }

    echo "CHECK de {$tabla} ampliado a los " . count($tipos) . " tipos.\n";
    exit(0);
}

// SQLite: la reconstrucción deja la tabla con un único CHECK, así que arregla también el caso
// de un segundo CHECK que rechace.
echo 'La base rechaza tipos de alerta en ' . $tabla . ': ' . ($rechazo ?? implode(', ', $faltan)) . "\n";

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

echo "Tabla {$tabla} reconstruida con los " . count($tipos) . " tipos de alerta.\n";
