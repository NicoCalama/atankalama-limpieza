<?php

declare(strict_types=1);

/**
 * Migración v6.18: inspección pre-entrega (en código «revision_entrega»): Recepción revisa la pieza
 * antes de entregarla al huésped, desde su tarjeta en Habitaciones.
 *
 * Agrega a una BD existente:
 *   - tablas motivos_revision_entrega y revisiones_entrega (+ índices),
 *   - permisos revision_entrega.registrar, revision_entrega.configurar y habitaciones.gestionar_edificios,
 *   - grants: registrar → Recepción; configurar → Supervisora; gestionar_edificios → todo rol que hoy
 *     tiene habitaciones.ver_todas MENOS Recepción (antes Edificios y Mapeo se abría con ver_todas: así
 *     nadie más pierde acceso). Los tres → todo rol con permisos.asignar_a_rol (el '__ALL__' de Admin
 *     solo se expande al sembrar),
 *   - los 8 motivos iniciales si la tabla está vacía.
 * El interruptor (alertas_config 'revision_entrega_no_ensucia') no se siembra: ausente = apagado.
 *
 * En installs frescos las tablas las crea init-db.php desde los schemas. Portable (SQLite dev +
 * MariaDB prod) e idempotente. Sin `const` ni funciones de nivel superior a propósito:
 * tests/Integration/MigracionRevisionEntregaTest lo incluye dos veces en el mismo proceso.
 * Ver docs/revision-entrega.md y docs/deploy-cpanel.md §11.15.
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

$esMaria       = in_array(Database::driver(), ['mysql', 'mariadb'], true);
$pdo           = Database::pdo();
$tMotivos      = Database::tabla('motivos_revision_entrega');
$tRevisiones   = Database::tabla('revisiones_entrega');
$tHabitaciones = Database::tabla('habitaciones');
$tUsuarios     = Database::tabla('usuarios');
$tEjecuciones  = Database::tabla('ejecuciones_checklist');
$tAuditorias   = Database::tabla('auditorias');

// 1. Tablas
if ($esMaria) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tMotivos} (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(60) NOT NULL UNIQUE,
            activo      TINYINT NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
            created_at  VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tRevisiones} (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            habitacion_id    INT NOT NULL,
            usuario_id       INT NOT NULL,
            resultado        VARCHAR(2) NOT NULL CHECK (resultado IN ('si', 'no')),
            motivo_id        INT NULL,
            comentario       VARCHAR(300) NULL,
            foto_ruta        VARCHAR(500) NULL,
            paso_a_sucia     TINYINT NOT NULL DEFAULT 0 CHECK (paso_a_sucia IN (0, 1)),
            estado_pieza     VARCHAR(40) NOT NULL,
            ejecucion_id     INT NULL,
            auditoria_id     INT NULL,
            idempotency_key  VARCHAR(64) NULL,
            created_at       VARCHAR(30) NOT NULL DEFAULT (CONCAT(REPLACE(UTC_TIMESTAMP(3), ' ', 'T'), 'Z')),
            UNIQUE KEY idx_revisiones_entrega_idem (idempotency_key),
            KEY idx_revisiones_entrega_hab_fecha (habitacion_id, created_at),
            KEY idx_revisiones_entrega_created (created_at),
            KEY idx_revisiones_entrega_auditoria (auditoria_id),
            FOREIGN KEY (habitacion_id) REFERENCES {$tHabitaciones}(id) ON DELETE RESTRICT,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT,
            FOREIGN KEY (motivo_id) REFERENCES {$tMotivos}(id) ON DELETE RESTRICT,
            FOREIGN KEY (ejecucion_id) REFERENCES {$tEjecuciones}(id) ON DELETE SET NULL,
            FOREIGN KEY (auditoria_id) REFERENCES {$tAuditorias}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} else {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tMotivos} (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre      TEXT NOT NULL UNIQUE,
            activo      INTEGER NOT NULL DEFAULT 1 CHECK (activo IN (0, 1)),
            created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$tRevisiones} (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            habitacion_id    INTEGER NOT NULL,
            usuario_id       INTEGER NOT NULL,
            resultado        TEXT NOT NULL CHECK (resultado IN ('si', 'no')),
            motivo_id        INTEGER,
            comentario       TEXT,
            foto_ruta        TEXT,
            paso_a_sucia     INTEGER NOT NULL DEFAULT 0 CHECK (paso_a_sucia IN (0, 1)),
            estado_pieza     TEXT NOT NULL,
            ejecucion_id     INTEGER,
            auditoria_id     INTEGER,
            idempotency_key  TEXT,
            created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
            FOREIGN KEY (habitacion_id) REFERENCES {$tHabitaciones}(id) ON DELETE RESTRICT,
            FOREIGN KEY (usuario_id) REFERENCES {$tUsuarios}(id) ON DELETE RESTRICT,
            FOREIGN KEY (motivo_id) REFERENCES {$tMotivos}(id) ON DELETE RESTRICT,
            FOREIGN KEY (ejecucion_id) REFERENCES {$tEjecuciones}(id) ON DELETE SET NULL,
            FOREIGN KEY (auditoria_id) REFERENCES {$tAuditorias}(id) ON DELETE SET NULL
        )"
    );
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revisiones_entrega_hab_fecha ON {$tRevisiones}(habitacion_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revisiones_entrega_created ON {$tRevisiones}(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_revisiones_entrega_auditoria ON {$tRevisiones}(auditoria_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_revisiones_entrega_idem ON {$tRevisiones}(idempotency_key)");
}
echo "Tablas {$tMotivos} y {$tRevisiones} listas.\n";

// 2. Permisos (permisos.codigo es la PK). Mismas descripciones que database/seeds/permisos.php.
$catalogo = array_values(array_filter(
    require __DIR__ . '/../database/seeds/permisos.php',
    static fn(array $p): bool => str_starts_with($p[0], 'revision_entrega.') || $p[0] === 'habitaciones.gestionar_edificios'
));
foreach ($catalogo as [$codigo, $descripcion, $categoria, $scope]) {
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

// 3. Grants: por rol (nombre o permiso que ya tiene) + todo rol que administra la matriz.
$rolesAdmin = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['permisos.asignar_a_rol']
);
$rolesPorNombre = static function (string $nombre): array {
    $rol = Database::fetchOne('SELECT id, nombre FROM #__roles WHERE nombre = ?', [$nombre]);
    if ($rol === null) {
        echo "No existe el rol {$nombre}: concede el permiso desde Ajustes → Roles si hace falta.\n";
        return [];
    }
    return [$rol];
};
$destinos = [
    'revision_entrega.registrar' => $rolesPorNombre('Recepción'),
    'revision_entrega.configurar' => $rolesPorNombre('Supervisora'),
    // Edificios y Mapeo se abría con habitaciones.ver_todas: lo conserva todo rol que lo tenía, menos Recepción.
    'habitaciones.gestionar_edificios' => Database::fetchAll(
        "SELECT DISTINCT r.id, r.nombre FROM #__roles r
           JOIN #__rol_permisos rp ON rp.rol_id = r.id
          WHERE rp.permiso_codigo = 'habitaciones.ver_todas' AND r.nombre <> 'Recepción'
          ORDER BY r.id"
    ),
];
foreach ($destinos as $codigo => $roles) {
    foreach (array_merge($roles, $rolesAdmin) as $rol) {
        $rolId = (int) $rol['id'];
        $ya = Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, $codigo]);
        if ($ya === null) {
            Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, $codigo]);
            echo "{$codigo} concedido al rol {$rol['nombre']}.\n";
        } else {
            echo "El rol {$rol['nombre']} ya tenía {$codigo}.\n";
        }
    }
}

// 4. Motivos iniciales, solo si la tabla está vacía (no re-crea los que alguien borró a mano).
if ((int) Database::fetchColumn('SELECT COUNT(*) FROM #__motivos_revision_entrega') === 0) {
    $motivos = require __DIR__ . '/../database/seeds/motivos_revision_entrega.php';
    foreach ($motivos as $nombre) {
        Database::execute('INSERT OR IGNORE INTO #__motivos_revision_entrega (nombre) VALUES (?)', [$nombre]);
    }
    echo "Motivos sembrados: " . count($motivos) . ".\n";
} else {
    echo "La tabla de motivos ya tenía datos: no se siembra.\n";
}

echo "Migración completa. Verifica con: php scripts/verificar-esquema.php\n";
