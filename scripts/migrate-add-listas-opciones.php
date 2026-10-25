<?php

declare(strict_types=1);

/**
 * Migración v7.3: listas de selección (pedido de gerencia del 10/10/2026: las acciones no llevan cuadro
 * de texto, se elige de una lista que se edita en Ajustes → Checklists y listas).
 *
 * Agrega a una BD existente:
 *   - tabla listas_opciones (+ índice),
 *   - motivos_revision_entrega.orden (los motivos del NO se ordenan como las demás listas), inicializado en
 *     orden alfabético con «Otro» al final si todavía estaban todos en 0,
 *   - auditorias.motivo_id (la opción de «rechazo» elegida; FK a listas_opciones),
 *   - tickets.tipo_id (v7.4: el tipo de problema elegido; FK a listas_opciones),
 *   - permiso listas.editar → todo rol con permisos.asignar_a_rol (el Admin; el '__ALL__' del seed solo
 *     se expande al sembrar). DEFAULT APLICADO (aprobado por el usuario): solo Admin edita las listas,
 *   - las opciones iniciales de cada lista que esté vacía (database/seeds/listas_opciones.php; desde la v7.4
 *     también mensaje a la mucama, tipo de problema y respuestas de un ticket).
 *
 * En installs frescos todo esto lo crean init-db.php y seed.php. Portable (SQLite dev + MariaDB prod) e
 * idempotente. Sin `const` ni funciones de nivel superior: un test lo incluye dos veces en el mismo proceso.
 * Ver docs/ajustes.md §2.3 y docs/deploy-cpanel.md §11.27.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$esMaria     = in_array(Database::driver(), ['mysql', 'mariadb'], true);
$pdo         = Database::pdo();
$tListas     = Database::tabla('listas_opciones');
$tMotivos    = Database::tabla('motivos_revision_entrega');
$tAuditorias = Database::tabla('auditorias');

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

// 1. Tabla (antes que la columna de auditorias: la FK de MariaDB exige que exista).
if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tListas} (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            lista       VARCHAR(30) NOT NULL CHECK (lista IN (
                'rechazo', 'saltar', 'reasignar', 'nota_recepcion', 'ticket_tipo', 'ticket_respuesta'
            )),
            nombre      VARCHAR(60) NOT NULL,
            activo      TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
            orden       INT NOT NULL DEFAULT 0,
            created_at  VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            UNIQUE KEY uq_listas_opciones_nombre (lista, nombre),
            KEY idx_listas_opciones_lista (lista, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tListas} (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            lista       TEXT NOT NULL CHECK (lista IN (
                'rechazo', 'saltar', 'reasignar', 'nota_recepcion', 'ticket_tipo', 'ticket_respuesta'
            )),
            nombre      TEXT NOT NULL,
            activo      INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
            orden       INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            UNIQUE (lista, nombre)
        )"
    );
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_listas_opciones_lista ON {$tListas}(lista, orden)");
}
echo "Tabla {$tListas} lista.\n";

// 2. Orden de los motivos del NO de Recepción.
if ($columnaExiste($tMotivos, 'orden')) {
    echo "{$tMotivos}.orden ya existe — omito.\n";
} else {
    $pdo->exec("ALTER TABLE {$tMotivos} ADD COLUMN orden " . ($esMaria ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'));
    echo "{$tMotivos}.orden agregada.\n";
}
if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__motivos_revision_entrega WHERE orden <> 0') === 0) {
    $motivos = Database::fetchAll('SELECT id, nombre FROM #__motivos_revision_entrega');
    // Alfabético en español con «Otro» al final (en PHP: el LOWER de SQLite no baja tildes ni la Ñ, y
    // comparar bytes pondría «Basura» antes que «Baño»). Sin intl: las tildes se aplanan y la ñ va tras la n.
    $clave = static fn(string $s): string => strtr(mb_strtolower($s), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n{',
    ]);
    usort($motivos, static function (array $a, array $b) use ($clave): int {
        $esOtroA = $clave((string) $a['nombre']) === 'otro';
        $esOtroB = $clave((string) $b['nombre']) === 'otro';
        if ($esOtroA !== $esOtroB) {
            return $esOtroA ? 1 : -1;
        }
        return strcmp($clave((string) $a['nombre']), $clave((string) $b['nombre']));
    });
    foreach ($motivos as $i => $m) {
        Database::execute('UPDATE #__motivos_revision_entrega SET orden = ? WHERE id = ?', [$i + 1, (int) $m['id']]);
    }
    echo "Orden inicial de los motivos del NO: " . count($motivos) . ".\n";
} else {
    echo "Los motivos del NO ya tenían orden — no se toca.\n";
}

// 3. Motivo del rechazo en auditorias.
if ($columnaExiste($tAuditorias, 'motivo_id')) {
    echo "{$tAuditorias}.motivo_id ya existe — omito.\n";
} elseif ($esMaria) {
    $pdo->exec("ALTER TABLE {$tAuditorias} ADD COLUMN motivo_id INT NULL");
    $pdo->exec("ALTER TABLE {$tAuditorias} ADD CONSTRAINT fk_{$tAuditorias}_motivo FOREIGN KEY (motivo_id) REFERENCES {$tListas}(id) ON DELETE RESTRICT");
    echo "{$tAuditorias}.motivo_id agregada (con FK).\n";
} else {
    $pdo->exec("ALTER TABLE {$tAuditorias} ADD COLUMN motivo_id INTEGER REFERENCES {$tListas}(id) ON DELETE RESTRICT");
    echo "{$tAuditorias}.motivo_id agregada.\n";
}

// 3b. Tipo de problema de un ticket (v7.4).
$tTickets = Database::tabla('tickets');
if ($columnaExiste($tTickets, 'tipo_id')) {
    echo "{$tTickets}.tipo_id ya existe — omito.\n";
} elseif ($esMaria) {
    $pdo->exec("ALTER TABLE {$tTickets} ADD COLUMN tipo_id INT NULL");
    $pdo->exec("ALTER TABLE {$tTickets} ADD CONSTRAINT fk_{$tTickets}_tipo FOREIGN KEY (tipo_id) REFERENCES {$tListas}(id) ON DELETE RESTRICT");
    echo "{$tTickets}.tipo_id agregada (con FK).\n";
} else {
    $pdo->exec("ALTER TABLE {$tTickets} ADD COLUMN tipo_id INTEGER REFERENCES {$tListas}(id) ON DELETE RESTRICT");
    echo "{$tTickets}.tipo_id agregada.\n";
}

// 4. Permiso listas.editar (mismas descripciones que database/seeds/permisos.php) → roles que administran la matriz.
foreach (require __DIR__ . '/../database/seeds/permisos.php' as [$codigo, $descripcion, $categoria, $scope]) {
    if ($codigo !== 'listas.editar') {
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
$rolesAdmin = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['permisos.asignar_a_rol']
);
foreach ($rolesAdmin as $rol) {
    $rolId = (int) $rol['id'];
    if (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, 'listas.editar']) === null) {
        Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, 'listas.editar']);
        echo "listas.editar concedido al rol {$rol['nombre']}.\n";
    } else {
        echo "El rol {$rol['nombre']} ya tenía listas.editar.\n";
    }
}

// 5. Opciones iniciales de cada lista vacía (no re-crea las que alguien desactivó). Solo las listas que
// acepta el CHECK de arriba: las que se sumaron después las siembra su propia migración (la v7.11,
// «sin_inspeccion», scripts/migrate-add-sin-inspeccion.php).
$listasV74 = ['rechazo', 'saltar', 'reasignar', 'nota_recepcion', 'ticket_tipo', 'ticket_respuesta'];
foreach (require __DIR__ . '/../database/seeds/listas_opciones.php' as $lista => $opciones) {
    if (!in_array($lista, $listasV74, true)) {
        continue;
    }
    if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__listas_opciones WHERE lista = ?', [$lista]) > 0) {
        echo "La lista {$lista} ya tenía opciones — no se siembra.\n";
        continue;
    }
    foreach ($opciones as $i => $nombre) {
        Database::execute('INSERT INTO #__listas_opciones (lista, nombre, orden) VALUES (?, ?, ?)', [$lista, $nombre, $i + 1]);
    }
    echo "Lista {$lista}: " . count($opciones) . " opciones sembradas.\n";
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
