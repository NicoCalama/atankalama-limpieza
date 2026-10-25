<?php

declare(strict_types=1);

/**
 * Migración v7.11: «No se pudo inspeccionar» (pedido de Nicolás, 25/10/2026; en reportes «Sin inspección»).
 *
 * 1. Amplía el CHECK de `auditorias.veredicto` a Auditoria::VEREDICTOS_VALIDOS (suma 'sin_inspeccion').
 * 2. Amplía el CHECK de `listas_opciones.lista` (suma la lista 'sin_inspeccion').
 * 3. Siembra las opciones iniciales de la lista, solo si está vacía (database/seeds/listas_opciones.php).
 * 4. Agrega el permiso `auditoria.sin_inspeccion` y se lo da a todo rol que hoy tiene `auditoria.aprobar`
 *    (decisión de Nicolás: lo usa quien hoy aprueba; en producción Admin tiene sus permisos en filas).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: re-correrlo no cambia nada.
 * - MariaDB: `MODIFY COLUMN … CHECK (…)`, nunca `DROP CONSTRAINT` (ERROR 1091 sobre un CHECK de columna,
 *   ver docs/incidente-2026-09-23.md §5). Si después la base sigue rechazando, hay otro CHECK (de tabla)
 *   que el MODIFY no toca: el script lo avisa y termina con error.
 * - SQLite no modifica un CHECK con ALTER: reconstruye la tabla desde su propio CREATE (de sqlite_master)
 *   con la lista nueva, copia las filas y recrea los índices.
 * En producción el mismo SQL va para phpMyAdmin en docs/deploy-cpanel.md §11.40.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Models\Auditoria;
use Atankalama\Limpieza\Services\EsquemaService;
use Atankalama\Limpieza\Services\ListasOpcionesService;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

// Listas de valores desde el código (no copias a mano). La de listas_opciones no incluye 'no_recepcion',
// que vive en su propia tabla.
$listasEnTabla = array_values(array_filter(
    ListasOpcionesService::LISTAS,
    static fn(string $l): bool => $l !== ListasOpcionesService::NO_RECEPCION
));
$cambios = [
    ['tabla' => 'auditorias', 'columna' => 'veredicto', 'valores' => Auditoria::VEREDICTOS_VALIDOS],
    ['tabla' => 'listas_opciones', 'columna' => 'lista', 'valores' => $listasEnTabla],
];

/** Lo que la base rechaza hoy de tabla.columna según el verificador de esquema (null = nada). */
$rechazoVivo = static function (string $tabla, string $columna): ?string {
    EsquemaService::limpiarCache();
    foreach ((new EsquemaService())->faltantes(true)['checks'] as $check) {
        if (str_starts_with($check, "{$tabla}.{$columna}:")) {
            return $check;
        }
    }
    return null;
};

foreach ($cambios as ['tabla' => $base, 'columna' => $columna, 'valores' => $valores]) {
    $tabla    = Database::tabla($base);
    $listaSql = "'" . implode("', '", $valores) . "'";

    if ($esMaria) {
        $row = $pdo->query("SHOW CREATE TABLE `{$tabla}`")->fetch(\PDO::FETCH_NUM);
        $definicion = (string) ($row[1] ?? '');
    } else {
        // fetchAll sobre un statement temporal: no deja un cursor abierto sobre sqlite_master, que
        // bloquearía el DROP TABLE de la reconstrucción.
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
    $rechazo = $rechazoVivo($base, $columna);
    if ($faltan === [] && $rechazo === null) {
        echo "{$tabla}.{$columna} ya acepta todos los valores. Nada que hacer.\n";
        continue;
    }
    echo "{$tabla}.{$columna} rechaza: " . ($rechazo ?? implode(', ', $faltan)) . "\n";

    if ($esMaria) {
        // Las dos columnas son VARCHAR(30) NOT NULL en los dos schemas y en el dump de producción.
        // $tabla y $columna son valores internos, no input de usuario: interpolar es seguro.
        $pdo->exec("ALTER TABLE `{$tabla}` MODIFY COLUMN {$columna} VARCHAR(30) NOT NULL CHECK ({$columna} IN ({$listaSql}))");
        $rechazo = $rechazoVivo($base, $columna);
        if ($rechazo !== null) {
            fwrite(STDERR, "El CHECK de la columna se amplió, pero la base sigue rechazando: {$rechazo}\n"
                . "Hay otro CHECK sobre `{$columna}`. Revisá SHOW CREATE TABLE {$tabla} y borrá ese.\n");
            exit(1);
        }
        echo "CHECK de {$tabla}.{$columna} ampliado.\n";
        continue;
    }

    // --- SQLite: reconstrucción desde su propio CREATE con la lista nueva ---
    $patron = '/(CHECK\s*\(\s*' . preg_quote($columna, '/') . '\s+IN\s*\()[^)]*(\))/i';
    $nuevaDef = preg_replace($patron, '${1}' . $listaSql . '${2}', $definicion, 1, $reemplazos);
    if ($nuevaDef === null || $reemplazos !== 1) {
        fwrite(STDERR, "No encontré el CHECK de {$columna} en el CREATE de {$tabla}. Recreá la base con scripts/init-db.php --fresh.\n");
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
    echo "Tabla {$tabla} reconstruida con el CHECK de {$columna} ampliado.\n";
}

// 3. Opciones iniciales de la lista nueva, solo si está vacía (no re-crea las que alguien desactivó).
$lista = ListasOpcionesService::SIN_INSPECCION;
$opciones = (require __DIR__ . '/../database/seeds/listas_opciones.php')[$lista];
if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__listas_opciones WHERE lista = ?', [$lista]) > 0) {
    echo "La lista {$lista} ya tenía opciones — no se siembra.\n";
} else {
    foreach ($opciones as $i => $nombre) {
        Database::execute('INSERT INTO #__listas_opciones (lista, nombre, orden) VALUES (?, ?, ?)', [$lista, $nombre, $i + 1]);
    }
    echo "Lista {$lista}: " . count($opciones) . " opciones sembradas.\n";
}

// 4. Permiso nuevo, para todo rol que hoy aprueba.
$permiso = 'auditoria.sin_inspeccion';
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if ($codigo === $permiso) {
        Database::execute(
            'INSERT OR IGNORE INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
    }
}
$roles = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['auditoria.aprobar']
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
