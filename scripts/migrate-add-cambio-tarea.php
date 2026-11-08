<?php

declare(strict_types=1);

/**
 * Migración v7.16: «Cambio Tarea» (pedido de gerencia, 06/11/2026; plan aprobado por Nicolás el 07/11/2026).
 *
 * 1. Amplía el CHECK de `listas_opciones.lista` (suma la lista 'area_apoyo').
 * 2. Crea la tabla `horas_otras_areas` (horas de una persona de Aseo en otra área, por día).
 * 3. Siembra las opciones iniciales de la lista, solo si está vacía (database/seeds/listas_opciones.php).
 * 4. Agrega el permiso `cambio_tarea.registrar` y se lo da a todo rol que hoy reparte piezas
 *    (`asignaciones.asignar_manual`: Supervisora y Admin).
 * 5. Actualiza la descripción de `listas.editar` (menciona la lista nueva).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: re-correrlo no cambia nada.
 * - MariaDB: `MODIFY COLUMN … CHECK (…)`, nunca `DROP CONSTRAINT` (ver docs/incidente-2026-09-23.md §5).
 * - SQLite no modifica un CHECK con ALTER: reconstruye la tabla desde su propio CREATE con la lista nueva.
 * En producción el mismo SQL va para phpMyAdmin en docs/deploy-cpanel.md §11.48.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Services\ListasOpcionesService;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

// ── 1. CHECK de listas_opciones.lista ─────────────────────────────────────────
// Lista de valores desde el código (no copias a mano). 'no_recepcion' vive en su propia tabla.
$valores = array_values(array_filter(
    ListasOpcionesService::LISTAS,
    static fn(string $l): bool => $l !== ListasOpcionesService::NO_RECEPCION
));
$tabla    = Database::tabla('listas_opciones');
$listaSql = "'" . implode("', '", $valores) . "'";

$rechazoVivo = static function (): ?string {
    EsquemaService::limpiarCache();
    foreach ((new EsquemaService())->faltantes(true)['checks'] as $check) {
        if (str_starts_with($check, 'listas_opciones.lista:')) {
            return $check;
        }
    }
    return null;
};

if ($esMaria) {
    $row = $pdo->query("SHOW CREATE TABLE `{$tabla}`")->fetch(\PDO::FETCH_NUM);
    $definicion = (string) ($row[1] ?? '');
} else {
    // fetchAll: no deja un cursor abierto sobre sqlite_master, que bloquearía el DROP TABLE.
    $filas = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($tabla)
    )->fetchAll(\PDO::FETCH_COLUMN);
    $definicion = (string) ($filas[0] ?? '');
}
if ($definicion === '') {
    fwrite(STDERR, "No existe la tabla {$tabla}. ¿La BD está inicializada? Corré scripts/init-db.php primero.\n");
    exit(1);
}

$faltan  = array_values(array_filter($valores, static fn(string $v): bool => !str_contains($definicion, "'{$v}'")));
$rechazo = $rechazoVivo();
if ($faltan === [] && $rechazo === null) {
    echo "{$tabla}.lista ya acepta todas las listas. Nada que hacer.\n";
} elseif ($esMaria) {
    echo "{$tabla}.lista rechaza: " . ($rechazo ?? implode(', ', $faltan)) . "\n";
    // VARCHAR(30) NOT NULL en los dos schemas y en el dump de producción. Valores internos: interpolar es seguro.
    $pdo->exec("ALTER TABLE `{$tabla}` MODIFY COLUMN lista VARCHAR(30) NOT NULL CHECK (lista IN ({$listaSql}))");
    $rechazo = $rechazoVivo();
    if ($rechazo !== null) {
        fwrite(STDERR, "El CHECK de la columna se amplió, pero la base sigue rechazando: {$rechazo}\n"
            . "Hay otro CHECK sobre `lista`. Revisá SHOW CREATE TABLE {$tabla} y borrá ese.\n");
        exit(1);
    }
    echo "CHECK de {$tabla}.lista ampliado.\n";
} else {
    echo "{$tabla}.lista rechaza: " . ($rechazo ?? implode(', ', $faltan)) . "\n";
    $patron = '/(CHECK\s*\(\s*lista\s+IN\s*\()[^)]*(\))/i';
    $nuevaDef = preg_replace($patron, '${1}' . $listaSql . '${2}', $definicion, 1, $reemplazos);
    if ($nuevaDef === null || $reemplazos !== 1) {
        fwrite(STDERR, "No encontré el CHECK de lista en el CREATE de {$tabla}. Recreá la base con scripts/init-db.php --fresh.\n");
        exit(1);
    }
    $nueva    = $tabla . '_nueva';
    $nuevaDef = preg_replace('/^CREATE\s+TABLE\s+"?' . preg_quote($tabla, '/') . '"?/i', "CREATE TABLE {$nueva}", $nuevaDef, 1);
    $indices  = $pdo->query(
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL AND tbl_name = " . $pdo->quote($tabla)
    )->fetchAll(\PDO::FETCH_COLUMN);

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    $pdo->exec($nuevaDef);
    $pdo->exec("INSERT INTO {$nueva} SELECT * FROM {$tabla}");
    $pdo->exec("DROP TABLE {$tabla}");
    $pdo->exec("ALTER TABLE {$nueva} RENAME TO {$tabla}");
    foreach ($indices as $sqlIndice) {
        $pdo->exec((string) $sqlIndice);
    }
    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
    echo "Tabla {$tabla} reconstruida con el CHECK de lista ampliado.\n";
}

// ── 2. Tabla horas_otras_areas ───────────────────────────────────────────────
$tHoras    = Database::tabla('horas_otras_areas');
$tUsuarios = Database::tabla('usuarios');
if ($esMaria) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tHoras}` (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id      INT NOT NULL,
        fecha           VARCHAR(10) NOT NULL,
        area_id         INT NOT NULL,
        minutos         INT NOT NULL CHECK (minutos > 0 AND minutos <= 720 AND minutos % 30 = 0),
        registrado_por  INT NULL,
        created_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
        updated_at      VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
        UNIQUE KEY uq_horas_otras_areas (usuario_id, fecha, area_id),
        KEY idx_horas_otras_areas_fecha (fecha),
        FOREIGN KEY (usuario_id) REFERENCES `{$tUsuarios}`(id) ON DELETE CASCADE,
        FOREIGN KEY (area_id) REFERENCES `{$tabla}`(id) ON DELETE RESTRICT,
        FOREIGN KEY (registrado_por) REFERENCES `{$tUsuarios}`(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tHoras} (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id      INTEGER NOT NULL,
        fecha           TEXT NOT NULL,
        area_id         INTEGER NOT NULL,
        minutos         INTEGER NOT NULL CHECK (minutos > 0 AND minutos <= 720 AND minutos % 30 = 0),
        registrado_por  INTEGER,
        created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        UNIQUE (usuario_id, fecha, area_id),
        FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE CASCADE,
        FOREIGN KEY (area_id) REFERENCES {$tabla}(id) ON DELETE RESTRICT,
        FOREIGN KEY (registrado_por) REFERENCES {$tUsuarios}(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_horas_otras_areas_fecha ON {$tHoras}(fecha)");
}
echo "Tabla {$tHoras} lista.\n";

// ── 3. Opciones iniciales de la lista nueva, solo si está vacía ──────────────
$lista = ListasOpcionesService::AREA_APOYO;
$opciones = (require __DIR__ . '/../database/seeds/listas_opciones.php')[$lista];
if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__listas_opciones WHERE lista = ?', [$lista]) > 0) {
    echo "La lista {$lista} ya tenía opciones — no se siembra.\n";
} else {
    foreach ($opciones as $i => $nombre) {
        Database::execute('INSERT INTO #__listas_opciones (lista, nombre, orden) VALUES (?, ?, ?)', [$lista, $nombre, $i + 1]);
    }
    echo "Lista {$lista}: " . count($opciones) . " opciones sembradas.\n";
}

// ── 4 y 5. Permiso nuevo para quien reparte piezas; descripción de listas.editar ─
$permiso = 'cambio_tarea.registrar';
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if ($codigo === $permiso) {
        Database::execute(
            'INSERT OR IGNORE INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
    }
    if ($codigo === 'listas.editar') {
        Database::execute('UPDATE #__permisos SET descripcion = ? WHERE codigo = ?', [$descripcion, $codigo]);
    }
}
$roles = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['asignaciones.asignar_manual']
);
foreach ($roles as $rol) {
    $rolId = (int) $rol['id'];
    if (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, $permiso]) === null) {
        Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, $permiso]);
        echo "{$permiso} concedido al rol {$rol['nombre']}.\n";
    } else {
        echo "El rol {$rol['nombre']} ya tenía {$permiso}.\n";
    }
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
