<?php

declare(strict_types=1);

/**
 * Migración v7.5: habitación de prueba (pedido de Nicolás del 10/10/2026: hacer los smoke tests en
 * producción sin tocar Cloudbeds ni el proceso real).
 *
 * Agrega a una BD existente:
 *   - habitaciones.es_prueba (0/1),
 *   - permiso habitaciones.ver_prueba → todo rol con permisos.asignar_a_rol (el Admin; el '__ALL__' del
 *     seed solo se expande al sembrar). DEFAULT APLICADO (aprobado por el usuario): solo Admin la ve,
 *   - una habitación «PRUEBA» por hotel con piezas, sin cloudbeds_room_id, «Aprobada», del tipo que se
 *     pase con --tipo=«nombre» o, si no, del tipo con más piezas activas de ese hotel (usa su checklist).
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: no repite la columna, el permiso ni la pieza de un
 * hotel que ya la tiene. Sin `const` ni funciones de nivel superior: un test lo incluye dos veces en el
 * mismo proceso. Ver docs/habitacion-prueba.md y docs/deploy-cpanel.md §11.31.
 *
 * Uso: php scripts/migrate-add-habitacion-prueba.php [--tipo=Doble]
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;
use Atankalama\Limpieza\Services\HabitacionesPrueba;

Config::load(dirname(__DIR__));

$esMaria = in_array(Database::driver(), ['mysql', 'mariadb'], true);
$pdo     = Database::pdo();
$tHab    = Database::tabla('habitaciones');

$tipoPedido = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--tipo=')) {
        $tipoPedido = trim(substr($arg, strlen('--tipo=')));
    }
}

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

// 1. Columna.
if ($columnaExiste($tHab, 'es_prueba')) {
    echo "{$tHab}.es_prueba ya existe — omito.\n";
} else {
    $tipoCol = $esMaria ? 'TINYINT' : 'INTEGER';
    $pdo->exec("ALTER TABLE {$tHab} ADD COLUMN es_prueba {$tipoCol} NOT NULL DEFAULT 0 CHECK (es_prueba IN (0, 1))");
    echo "{$tHab}.es_prueba agregada.\n";
}

// 2. Permiso (misma descripción que database/seeds/permisos.php) → roles que administran la matriz.
$permiso = HabitacionesPrueba::PERMISO_VER;
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if ($codigo !== $permiso) {
        continue;
    }
    if (Database::fetchOne('SELECT codigo FROM #__permisos WHERE codigo = ?', [$codigo]) === null) {
        Database::execute(
            'INSERT INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
        echo "Permiso {$codigo} creado.\n";
    } else {
        echo "Permiso {$codigo} ya existía.\n";
    }
}
foreach (Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['permisos.asignar_a_rol']
) as $rol) {
    $rolId = (int) $rol['id'];
    if (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, $permiso]) === null) {
        Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, $permiso]);
        echo "{$permiso} concedido al rol {$rol['nombre']}.\n";
    } else {
        echo "El rol {$rol['nombre']} ya tenía {$permiso}.\n";
    }
}

// 3. Una «PRUEBA» por hotel con piezas.
$tipoIdPedido = null;
if ($tipoPedido !== null && $tipoPedido !== '') {
    $tipoIdPedido = Database::fetchColumn('SELECT id FROM #__tipos_habitacion WHERE nombre = ?', [$tipoPedido]);
    if ($tipoIdPedido === false || $tipoIdPedido === null) {
        fwrite(STDERR, "No existe el tipo de habitación «{$tipoPedido}». No se creó ninguna habitación de prueba.\n");
        exit(1);
    }
    $tipoIdPedido = (int) $tipoIdPedido;
}
foreach (Database::fetchAll('SELECT id, codigo, nombre FROM #__hoteles ORDER BY id') as $hotel) {
    $hotelId = (int) $hotel['id'];
    if (Database::fetchOne('SELECT 1 FROM #__habitaciones WHERE hotel_id = ? AND es_prueba = 1', [$hotelId]) !== null) {
        echo "{$hotel['nombre']} ya tiene su habitación de prueba — omito.\n";
        continue;
    }
    if (Database::fetchOne("SELECT 1 FROM #__habitaciones WHERE hotel_id = ? AND numero = 'PRUEBA'", [$hotelId]) !== null) {
        fwrite(STDERR, "{$hotel['nombre']}: ya hay una pieza «PRUEBA» que no es de prueba — revisar a mano, no se crea.\n");
        continue;
    }
    $tipoId = $tipoIdPedido ?? Database::fetchColumn(
        'SELECT tipo_habitacion_id FROM #__habitaciones
          WHERE hotel_id = ? AND activa = 1 AND es_espacio_comun = 0
          GROUP BY tipo_habitacion_id
          ORDER BY COUNT(*) DESC, tipo_habitacion_id
          LIMIT 1',
        [$hotelId]
    );
    if ($tipoId === false || $tipoId === null) {
        echo "{$hotel['nombre']} no tiene habitaciones — no se crea la de prueba.\n";
        continue;
    }
    Database::execute(
        "INSERT INTO #__habitaciones (hotel_id, numero, tipo_habitacion_id, estado, activa, es_espacio_comun, es_prueba)
         VALUES (?, 'PRUEBA', ?, 'aprobada', 1, 0, 1)",
        [$hotelId, (int) $tipoId]
    );
    $tipoNombre = (string) Database::fetchColumn('SELECT nombre FROM #__tipos_habitacion WHERE id = ?', [(int) $tipoId]);
    echo "{$hotel['nombre']}: habitación de prueba «PRUEBA» creada (tipo {$tipoNombre}).\n";
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
