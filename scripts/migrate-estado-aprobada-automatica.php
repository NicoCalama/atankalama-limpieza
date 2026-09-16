<?php

declare(strict_types=1);

/**
 * Migración: agrega el estado 'aprobada_automatica' (habitaciones.estado) y el
 * veredicto 'aprobado_automatico' (auditorias.veredicto), para el cierre de día
 * automático de las 23:55 (ver scripts/aprobar-pendientes-cierre-dia.php). También
 * crea el usuario "Sistema" (inactivo, sin login) usado como auditor_id en esas
 * auditorías automáticas.
 *
 * A diferencia de las migraciones ADD COLUMN de este proyecto, esta reescribe dos
 * CHECK constraints existentes — no hay ALTER TABLE ... MODIFY CHECK portable, así
 * que en MariaDB se busca el nombre real del constraint por introspección
 * (information_schema) y se recrea. Si la introspección no encuentra exactamente
 * un constraint, NO improvisa: imprime el SQL exacto para correrlo a mano por
 * phpMyAdmin (mismo patrón ya usado en este proyecto para cambios de esquema
 * puntuales) y sigue con lo demás.
 *
 * En SQLite (dev) los CHECK constraints no se pueden alterar sin reconstruir la
 * tabla; como la BD de dev es descartable, el script solo avisa: recreala con
 * `php scripts/init-db.php --fresh`.
 *
 * Uso:
 *   php scripts/migrate-estado-aprobada-automatica.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

$driver  = Database::driver();
$esMaria = $driver === 'mysql' || $driver === 'mariadb';
$pdo     = Database::pdo();

if (!$esMaria) {
    echo "SQLite (dev): los CHECK constraints no se pueden alterar in-place.\n";
    echo "Recrea la BD de desarrollo con:  php scripts/init-db.php --fresh\n";
} else {
    /**
     * Busca el nombre del CHECK constraint de una tabla cuya cláusula contenga
     * un valor distintivo (ej. un valor del enum viejo), y lo recrea con la
     * lista de valores nueva.
     */
    $recrearCheck = function (string $tablaLogica, string $columna, string $marcadorDistintivo, array $valoresNuevos) use ($pdo): void {
        $tabla = Database::tabla($tablaLogica);

        $stmt = $pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
               FROM information_schema.TABLE_CONSTRAINTS tc
               JOIN information_schema.CHECK_CONSTRAINTS cc
                 ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
              WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = \'CHECK\''
        );
        $stmt->execute([$tabla]);
        $filas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $candidatos = array_values(array_filter(
            $filas,
            static fn(array $f) => str_contains((string) $f['CHECK_CLAUSE'], $marcadorDistintivo)
        ));

        $listaSql = "'" . implode("', '", $valoresNuevos) . "'";
        $sqlManual = "ALTER TABLE {$tabla} DROP CONSTRAINT <NOMBRE>;\n"
            . "ALTER TABLE {$tabla} ADD CONSTRAINT <NOMBRE> CHECK ({$columna} IN ({$listaSql}));";

        if (count($candidatos) !== 1) {
            echo "  {$tabla}.{$columna}: no encontré exactamente un CHECK constraint (encontré " . count($candidatos) . ") — corre esto a mano por phpMyAdmin, reemplazando <NOMBRE> por el que corresponda:\n";
            foreach ($filas as $f) {
                echo "    - {$f['CONSTRAINT_NAME']}: {$f['CHECK_CLAUSE']}\n";
            }
            echo "  {$sqlManual}\n";
            return;
        }

        $nombre = (string) $candidatos[0]['CONSTRAINT_NAME'];

        try {
            $pdo->exec("ALTER TABLE {$tabla} DROP CONSTRAINT {$nombre}");
            $pdo->exec("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} CHECK ({$columna} IN ({$listaSql}))");
            echo "  {$tabla}.{$columna}: constraint '{$nombre}' actualizado.\n";
        } catch (\Throwable $e) {
            echo "  {$tabla}.{$columna}: falló el ALTER automático ({$e->getMessage()}). Corre a mano:\n";
            echo '  ' . str_replace('<NOMBRE>', $nombre, $sqlManual) . "\n";
        }
    };

    echo "Estados/veredictos — cierre de día automático:\n";
    $recrearCheck(
        'habitaciones',
        'estado',
        'completada_pendiente_auditoria',
        ['sucia', 'en_progreso', 'completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion', 'aprobada_automatica', 'rechazada']
    );
    $recrearCheck(
        'auditorias',
        'veredicto',
        'aprobado_con_observacion',
        ['aprobado', 'aprobado_con_observacion', 'aprobado_automatico', 'rechazado']
    );
}

// Usuario "Sistema": inactivo (activo=0, nunca puede loguearse), usado solo como
// auditor_id en las auditorías automáticas del cierre de día. Idempotente por rut.
$tablaUsuarios = Database::tabla('usuarios');
$rutSistema    = 'SISTEMA-CRON';
$existe = $pdo->prepare("SELECT id FROM {$tablaUsuarios} WHERE rut = ?");
$existe->execute([$rutSistema]);
$idExistente = $existe->fetchColumn();

if ($idExistente !== false) {
    echo "Usuario 'Sistema' ya existe (id={$idExistente}) — omito.\n";
} else {
    $hashInutilizable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $insert = $pdo->prepare(
        "INSERT INTO {$tablaUsuarios} (rut, nombre, email, password_hash, requiere_cambio_pwd, activo)
         VALUES (?, ?, NULL, ?, 0, 0)"
    );
    $insert->execute([$rutSistema, 'Sistema', $hashInutilizable]);
    echo "Usuario 'Sistema' creado (id=" . $pdo->lastInsertId() . ", inactivo, sin login).\n";
}

echo "Migración completa.\n";
